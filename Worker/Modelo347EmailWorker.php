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

namespace FacturaScripts\Plugins\Modelo347\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\Modelo347Email;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * Envía el email del Modelo 347 a un cliente o proveedor con sus importes trimestrales. Atiende los
 * eventos Modelo347.SendCustomersEmails y Modelo347.SendSuppliersEmails, que encola Lib/Modelo347Email
 * con un destinatario por evento. El asunto y el cuerpo salen de las notificaciones por email
 * modelo347-customers y modelo347-suppliers. El remitente es la empresa del ejercicio.
 *
 * Lib/Modelo347Email programa cada email unos segundos después del anterior, pero el cron ejecuta
 * seguidos todos los eventos que ya han vencido. Por eso, dentro de un mismo proceso, el worker deja al
 * menos MIN_GAP_MS milisegundos entre dos envíos; un envío suelto no espera nada.
 *
 * También procesa los eventos de versiones anteriores, que llevaban todos los destinatarios en el
 * parámetro 'data'.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo347EmailWorker extends WorkerClass
{
    /** Milisegundos mínimos entre dos envíos del mismo proceso. */
    const MIN_GAP_MS = 500;

    /** Intentos máximos de envío de un email, contando el primero. */
    const MAX_ATTEMPTS = 3;

    /** Segundos de espera antes de reintentar un email que no se ha podido enviar. */
    const RETRY_DELAY = 300;

    /** @var float|null Momento (microtime) del último envío de este proceso. */
    private static $lastSend = null;

    /**
     * Envía el email del evento (o, con el formato anterior, los de todos sus destinatarios).
     *
     * @param WorkEvent $event evento de la cola con el destinatario en sus parámetros.
     *
     * @return bool siempre true (resultado de done()). Si el email no sale, se reintenta hasta MAX_ATTEMPTS veces con un evento nuevo y el fallo queda en el log.
     */
    public function run(WorkEvent $event): bool
    {
        $type = $event->name === Modelo347Email::EVENT_SUPPLIERS ?
            Modelo347Email::TYPE_SUPPLIERS :
            Modelo347Email::TYPE_CUSTOMERS;

        // formato anterior: todos los destinatarios en un único evento
        $data = $event->param('data');
        if (is_array($data)) {
            $exercise = new Ejercicio();
            $exercise->load($event->value);
            $params = [
                'amount' => $event->param('amount', 0),
                'codejercicio' => $event->value,
                'idempresa' => $exercise->idempresa,
                'year' => empty($exercise->fechainicio) ? $event->value : Modelo347Email::year($exercise),
            ];
            foreach ($data as $row) {
                $this->sendEmail(array_merge($params, $row), $type);
            }
            return $this->done();
        }

        // el ejercicio va en el valor del evento: lo pasamos para poder reintentar el envío
        $this->sendEmail(array_merge(['codejercicio' => $event->value], $event->params()), $type);
        return $this->done();
    }

    /**
     * Vuelve a encolar el email de un destinatario que no se ha podido enviar, RETRY_DELAY segundos
     * después, mientras no se hayan agotado los MAX_ATTEMPTS intentos. El número de intento viaja en
     * el parámetro 'attempt' del evento, así que el evento nuevo no se toma por un duplicado.
     *
     * @param array  $params parámetros del destinatario (los mismos que recibe sendEmail()).
     * @param string $type   'customers' o 'suppliers'.
     *
     * @return bool true si se ha encolado un nuevo intento.
     */
    protected function retry(array $params, string $type): bool
    {
        $attempt = (int)($params['attempt'] ?? 1);
        if ($attempt >= self::MAX_ATTEMPTS || empty($params['codejercicio'])) {
            return false;
        }

        $params['attempt'] = $attempt + 1;
        return WorkQueue::sendFuture(self::RETRY_DELAY, Modelo347Email::eventName($type), (string)$params['codejercicio'], $params);
    }

    /**
     * Envía el email del Modelo 347 a un destinatario con la notificación por email del tipo. Los
     * importes se formatean como moneda antes de sustituir las etiquetas {amount}, {t1}..{t4} y {total}.
     *
     * @param array  $params email, name, cifnif, t1-t4, total, amount, year e idempresa.
     * @param string $type 'customers' o 'suppliers'.
     * @return bool
     */
    protected function sendEmail(array $params, string $type): bool
    {
        $email = $params['email'] ?? '';
        if (empty($email) || false === Validator::email($email)) {
            Tools::log()->warning('347-no-email', [
                '%name%' => $params['name'] ?? '',
                '%cifnif%' => $params['cifnif'] ?? '',
            ]);
            return false;
        }

        // la notificación guarda el asunto y el cuerpo que puede personalizar el usuario
        $notification = new EmailNotification();
        if (false === $notification->load(Modelo347Email::notificationName($type))) {
            Tools::log()->warning('email-notification-not-exists', ['%name%' => Modelo347Email::notificationName($type)]);
            return false;
        }
        if (false === (bool)$notification->enabled) {
            Tools::log()->warning('email-notification-disabled', ['%name%' => Modelo347Email::notificationName($type)]);
            return false;
        }

        $year = (string)($params['year'] ?? '');
        $values = [
            'amount' => Tools::money((float)($params['amount'] ?? 0)),
            'cifnif' => $params['cifnif'] ?? '',
            'email' => $email,
            'name' => $params['name'] ?? '',
            't1' => Tools::money((float)($params['t1'] ?? 0)),
            't2' => Tools::money((float)($params['t2'] ?? 0)),
            't3' => Tools::money((float)($params['t3'] ?? 0)),
            't4' => Tools::money((float)($params['t4'] ?? 0)),
            'total' => Tools::money((float)($params['total'] ?? 0)),
            'year' => $year,
            // plazo de disconformidad: 20 de febrero del año siguiente al declarado
            'deadline' => is_numeric($year) ? Tools::date(((int)$year + 1) . '-02-20') : '',
        ];

        $mail = NewMail::create()->to($email, $values['name']);
        $mail->title = MailNotifier::getText((string)$notification->subject, $values);
        $mail->text = MailNotifier::getText((string)$notification->body, $values);

        // el remitente es la empresa del ejercicio, no la predeterminada
        $company = new Empresa();
        if (false === empty($params['idempresa']) && $company->load($params['idempresa'])) {
            $mail->empresa = $company;
            $mail->fromName = $company->nombrecorto;
        }

        $this->waitForGap();
        $sent = $mail->send();
        self::$lastSend = microtime(true);
        if ($sent) {
            return true;
        }

        // el servidor de correo puede fallar de forma puntual: lo reintentamos más tarde unas pocas veces
        if ($this->retry($params, $type)) {
            Tools::log()->warning('347-email-retry', [
                '%name%' => $values['name'],
                '%email%' => $email,
                '%minutes%' => (int)(self::RETRY_DELAY / 60),
            ]);
            return false;
        }

        Tools::log()->warning('347-email-not-sent', [
            '%name%' => $values['name'],
            '%email%' => $email,
        ]);
        return false;
    }

    /**
     * Espera, si hace falta, hasta que hayan pasado MIN_GAP_MS milisegundos desde el último envío de
     * este proceso, para no mandar los emails en ráfaga cuando el cron procesa muchos eventos seguidos.
     */
    protected function waitForGap(): void
    {
        if (null === self::$lastSend) {
            return;
        }

        $remaining = self::MIN_GAP_MS * 1000 - (int)((microtime(true) - self::$lastSend) * 1000000);
        if ($remaining > 0) {
            usleep($remaining);
        }
    }
}
