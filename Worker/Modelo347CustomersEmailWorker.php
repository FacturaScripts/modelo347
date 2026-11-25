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

use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Model\EmailNotification;

/**
 * Envía a los clientes la carta informativa del modelo 347 mediante su notificación por email.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo347CustomersEmailWorker extends WorkerClass
{
    /** Nombre de la notificación por email de la carta. */
    const NOTIFICATION = 'modelo347-customers';

    /**
     * Envía la carta a cada destinatario de los datos del evento.
     * El valor del evento es el código del ejercicio y sus parámetros son
     * 'data' (filas con email, name, cifnif, t1..t4 y total) y 'amount' (importe mínimo).
     */
    public function run(WorkEvent $event): bool
    {
        $data = $event->param('data');
        if (empty($data) || false === $this->isNotificationEnabled()) {
            return $this->done();
        }

        $sent = 0;
        $noEmail = 0;

        $amount = Tools::money((float)$event->param('amount'));
        $year = Ejercicios::get($event->value)->year();

        foreach ($data as $row) {
            $email = $row['email'] ?? '';
            if (empty($email) || false === Validator::email($email)) {
                $noEmail++;
                Tools::log()->warning('347-no-email', [
                    '%name%' => $row['name'] ?? '',
                    '%cifnif%' => $row['cifnif'] ?? '',
                ]);
                continue;
            }

            $params = [
                'amount' => $amount,
                'cifnif' => $row['cifnif'] ?? '',
                'email' => $email,
                'name' => $row['name'] ?? '',
                't1' => Tools::money((float)$row['t1']),
                't2' => Tools::money((float)$row['t2']),
                't3' => Tools::money((float)$row['t3']),
                't4' => Tools::money((float)$row['t4']),
                'total' => Tools::money((float)$row['total']),
                'year' => $year,
            ];

            if (MailNotifier::send(self::NOTIFICATION, $email, $row['name'] ?? '', $params)) {
                $sent++;
            }
        }

        Tools::log()->info('347-emails-sent', ['%sent%' => $sent, '%noEmail%' => $noEmail]);

        return $this->done();
    }

    /**
     * Indica si la notificación existe y está activada; si no, lo registra en el log.
     */
    protected function isNotificationEnabled(): bool
    {
        $notification = new EmailNotification();
        if (false === $notification->load(self::NOTIFICATION)) {
            Tools::log()->warning('email-notification-not-exists', ['%name%' => self::NOTIFICATION]);
            return false;
        }

        if (false === $notification->enabled) {
            Tools::log()->warning('email-notification-disabled', ['%name%' => self::NOTIFICATION]);
            return false;
        }

        return true;
    }
}
