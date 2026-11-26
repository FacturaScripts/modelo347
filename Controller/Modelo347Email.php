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
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\Modelo347Email as Modelo347EmailLib;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\EmailNotification;

/**
 * Selección de los clientes o proveedores del modelo 347 a los que enviar la carta informativa por email.
 * Recibe los filtros de Modelo347 y el tipo (customers o suppliers) en 'activetab'. El email de cada tercero
 * se busca con Lib/Modelo347Email y se encola un evento por destinatario, como en el envío completo.
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 */
class Modelo347Email extends Modelo347
{
    /** @var array Destinatarios con email del tipo para la vista: code, cifnif, name, email y total. */
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

    /** @var array Destinatarios con email preparados por Lib/Modelo347Email::recipients(), con sus importes. */
    private $recipientsData = [];

    /**
     * Carga los filtros y los destinatarios del tipo indicado, solo si el usuario puede modificar.
     */
    protected function defaultAction(): void
    {
        $this->type = $this->activetab === 'suppliers' ? 'suppliers' : 'customers';
        $this->activetab = $this->type;
        $this->loadFilters();

        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
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
     * Carga la notificación de la carta del tipo y avisa si no existe o está desactivada.
     */
    protected function loadNotification(): void
    {
        $name = Modelo347EmailLib::notificationName($this->type);
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
     * Calcula el modelo y prepara la lista de destinatarios con email del tipo, ordenada por nombre.
     * El email se busca en la ficha y en las direcciones del tercero (Lib/Modelo347Email::findEmail()).
     */
    protected function loadRecipients(): void
    {
        $this->calculate();
        $rows = $this->type === 'customers' ? $this->customersData : $this->suppliersData;
        $this->recipientsData = Modelo347EmailLib::recipients($rows, $this->type)['recipients'];

        $this->recipients = [];
        foreach ($this->recipientsData as $recipient) {
            $this->recipients[] = [
                'code' => $recipient['code'],
                'cifnif' => (string)$recipient['cifnif'],
                'name' => Tools::fixHtml((string)$recipient['name']),
                'email' => $recipient['email'],
                'total' => Tools::money($recipient['total']),
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
        if (false === $this->permissions->allowUpdate) {
            $result['message'] = Tools::trans('not-allowed-modify');
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
     * Encola un email por cada destinatario seleccionado, con los importes recalculados en el servidor.
     * Cada tercero recibe su propio email aunque comparta dirección con otro, porque sus importes son
     * distintos.
     *
     * @param array $codes códigos de los clientes o proveedores seleccionados.
     * @return array ['ok', 'codes' => códigos encolados, 'queued', 'message']
     */
    private function queueSelected(array $codes): array
    {
        $codes = array_map('strval', $codes);
        $selected = array_values(array_filter(
            $this->recipientsData,
            fn(array $recipient) => in_array($recipient['code'], $codes, true)
        ));
        if (empty($selected)) {
            return ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => Tools::trans('347-no-selected')];
        }

        if (false === NewMail::create()->canSendMail()) {
            return ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => Tools::trans('email-not-configured')];
        }

        $exercise = new Ejercicio();
        if (false === $exercise->load($this->codejercicio)) {
            return ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => Tools::trans('exercise-not-found')];
        }

        if (false === Modelo347EmailLib::isNotificationEnabled($this->type)) {
            $message = Tools::trans('email-notification-disabled', ['%name%' => Modelo347EmailLib::notificationName($this->type)]);
            return ['ok' => false, 'codes' => [], 'queued' => 0, 'message' => $message];
        }

        $queued = Modelo347EmailLib::queueRecipients($exercise, $this->amount, $selected, $this->type);
        return [
            'ok' => $queued > 0,
            'codes' => array_column($selected, 'code'),
            'queued' => $queued,
            'message' => Tools::trans('347-emails-queued-count', ['%count%' => $queued]),
        ];
    }
}
