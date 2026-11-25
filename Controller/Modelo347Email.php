<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo347\Controller;

use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Selección de los clientes o proveedores del modelo 347 a los que enviar la carta informativa por email.
 * Recibe los filtros de Modelo347 y el tipo (customers o suppliers) en 'activetab'.
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 */
class Modelo347Email extends Modelo347
{
    /** @var array Destinatarios con email del tipo: code, cifnif, name, email y total. */
    public $recipients = [];

    /** @var string Tipo de destinatarios: customers o suppliers. */
    public $type = 'customers';

    /** @var EmailNotification|null Notificación de la carta del tipo, si existe. */
    private $notification;

    /**
     * Devuelve el cuerpo de la notificación en HTML, con el importe y el año sustituidos
     * y el resto de etiquetas resaltadas.
     */
    public function getNotificationBody(): string
    {
        return $this->notification ? $this->previewText((string)$this->notification->body) : '';
    }

    /**
     * Devuelve el asunto de la notificación en HTML, con el importe y el año sustituidos
     * y el resto de etiquetas resaltadas.
     */
    public function getNotificationSubject(): string
    {
        return $this->notification ? $this->previewText(Tools::fixHtml((string)$this->notification->subject)) : '';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['showonmenu'] = false;
        $data['title'] = '347-select-recipients';
        $data['icon'] = 'fa-solid fa-envelope';
        return $data;
    }

    /**
     * Carga los filtros y los destinatarios del tipo indicado, solo si el usuario puede exportar.
     */
    protected function defaultAction(): void
    {
        $this->type = $this->activetab === 'suppliers' ? 'suppliers' : 'customers';
        $this->activetab = $this->type;
        $this->loadFilters();

        if (false === $this->permissions->allowExport) {
            Tools::log()->warning('access-denied');
            return;
        }

        $this->loadRecipients();
        $this->loadNotification();
    }

    /**
     * Ejecuta la acción solicitada. Sustituye las acciones de Modelo347.
     *
     * @param string $action
     */
    protected function execAction(string $action): void
    {
        if ($action === 'send-selected') {
            $this->sendSelectedAction();
        }
    }

    /**
     * Devuelve las filas del evento de envío para los códigos indicados,
     * con los importes recalculados y sin repetir email.
     *
     * @param string[] $codes
     * @return array
     */
    protected function getEmailData(array $codes): array
    {
        $isCustomer = $this->type === 'customers';
        $rows = $isCustomer ? $this->customersData : $this->suppliersData;
        $nameField = $isCustomer ? 'cliente' : 'proveedor';

        $data = [];
        $sentEmails = [];
        foreach ($this->recipients as $recipient) {
            if (false === in_array($recipient['code'], $codes, true) || isset($sentEmails[$recipient['email']])) {
                continue;
            }

            $sentEmails[$recipient['email']] = true;
            $row = $rows[$recipient['code']];
            $data[] = [
                'email' => $recipient['email'],
                'name' => $row[$nameField],
                'cifnif' => $row['cifnif'],
                't1' => $row['t1'],
                't2' => $row['t2'],
                't3' => $row['t3'],
                't4' => $row['t4'],
                'total' => $row['total'],
            ];
        }
        return $data;
    }

    /**
     * Carga la notificación de la carta del tipo y avisa si no existe o está desactivada.
     */
    protected function loadNotification(): void
    {
        $name = 'modelo347-' . $this->type;
        $notification = new EmailNotification();
        if (false === $notification->load($name)) {
            Tools::log()->warning('email-notification-not-exists', ['%name%' => $name]);
            return;
        }

        if (false === $notification->enabled) {
            Tools::log()->warning('email-notification-disabled', ['%name%' => $name]);
        }
        $this->notification = $notification;
    }

    /**
     * Calcula los datos del tipo y prepara la lista de destinatarios con email, ordenada por nombre.
     */
    protected function loadRecipients(): void
    {
        $this->recipients = [];
        if ($this->type === 'customers') {
            $this->loadCustomersData();
            $rows = $this->customersData;
            $model = new Cliente();
            $nameField = 'cliente';
        } else {
            $this->loadSuppliersData();
            $rows = $this->suppliersData;
            $model = new Proveedor();
            $nameField = 'proveedor';
        }

        foreach ($rows as $code => $row) {
            if (false === $model->load($code) || empty($model->email)) {
                continue;
            }

            $this->recipients[] = [
                'code' => (string)$code,
                'cifnif' => (string)$row['cifnif'],
                'name' => Tools::fixHtml((string)$row[$nameField]),
                'email' => $model->email,
                'total' => Tools::money((float)$row['total']),
            ];
        }

        usort($this->recipients, fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
    }

    /**
     * Encola el envío de la carta a los destinatarios seleccionados y responde en JSON
     * con los códigos enviados, el número de emails encolados y un token nuevo.
     */
    protected function sendSelectedAction(): void
    {
        $this->setTemplate(false);

        $result = ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => ''];
        if (false === $this->permissions->allowExport) {
            $result['message'] = Tools::trans('access-denied');
        } elseif (false === $this->validateFormToken()) {
            $result['message'] = Tools::trans('invalid-request');
        } else {
            $result = $this->queueSelected($this->request->request->getArray('codes'));
        }

        $result['token'] = $this->multiRequestProtection->newToken();
        $this->response->json($result);
    }

    /**
     * Escapa el texto, sustituye el importe y el año y resalta el resto de etiquetas {campo}.
     *
     * @param string $text
     * @return string
     */
    private function previewText(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES);
        $html = str_replace(
            ['{amount}', '{year}'],
            [Tools::money($this->amount), (string)Ejercicios::get($this->codejercicio)->year()],
            $html
        );
        return preg_replace('/\{(\w+)}/', '<mark>{$1}</mark>', $html);
    }

    /**
     * Encola el evento de envío del tipo con los códigos seleccionados.
     *
     * @param array $codes
     * @return array
     */
    private function queueSelected(array $codes): array
    {
        $codes = array_map('strval', $codes);
        $data = $this->getEmailData($codes);
        if (empty($data)) {
            return ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => Tools::trans('347-no-selected')];
        }

        $event = $this->type === 'customers' ? 'Modelo347.SendCustomersEmails' : 'Modelo347.SendSuppliersEmails';
        WorkQueue::send($event, $this->codejercicio, ['data' => $data, 'amount' => $this->amount]);

        $validCodes = array_column($this->recipients, 'code');
        return [
            'ok' => true,
            'codes' => array_values(array_intersect($validCodes, $codes)),
            'queued' => count($data),
            'message' => Tools::trans('347-emails-queued-count', ['%count%' => count($data)]),
        ];
    }
}
