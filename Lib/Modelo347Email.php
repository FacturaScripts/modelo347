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

namespace FacturaScripts\Plugins\Modelo347\Lib;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Prepara y encola los emails del Modelo 347: busca el email de cada tercero declarado y crea un evento
 * de la cola de trabajos por destinatario, que envía Worker/Modelo347EmailWorker con la notificación por
 * email del tipo (modelo347-customers o modelo347-suppliers), cuyo asunto y cuerpo puede personalizar el
 * usuario.
 *
 * Un evento por destinatario evita que, si el proceso se corta a mitad, se repita el envío a todos los
 * que ya lo habían recibido. Antes de encolar se comprueba que no quede pendiente un envío anterior del
 * mismo ejercicio y tipo, para que pulsar dos veces «Enviar» no duplique los emails.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo347Email
{
    /** Evento de la cola para los emails a clientes. */
    const EVENT_CUSTOMERS = 'Modelo347.SendCustomersEmails';

    /** Evento de la cola para los emails a proveedores. */
    const EVENT_SUPPLIERS = 'Modelo347.SendSuppliersEmails';

    /** Notificación por email de la carta a clientes. */
    const NOTIFICATION_CUSTOMERS = 'modelo347-customers';

    /** Notificación por email de la carta a proveedores. */
    const NOTIFICATION_SUPPLIERS = 'modelo347-suppliers';

    /** Tipo de tercero: clientes. */
    const TYPE_CUSTOMERS = 'customers';

    /** Tipo de tercero: proveedores. */
    const TYPE_SUPPLIERS = 'suppliers';

    /**
     * Devuelve el evento de la cola que corresponde a un tipo de tercero.
     *
     * @param string $type 'customers' o 'suppliers'.
     * @return string
     */
    public static function eventName(string $type): string
    {
        return $type === self::TYPE_SUPPLIERS ? self::EVENT_SUPPLIERS : self::EVENT_CUSTOMERS;
    }

    /**
     * Devuelve el email al que enviar el resumen de un cliente o proveedor. Usa el email de la ficha; si
     * no tiene o no es válido, el de su dirección predeterminada y, después, el de cualquiera de sus
     * direcciones o contactos. Devuelve una cadena vacía si no encuentra ninguno válido.
     *
     * @param Cliente|Proveedor $subject cliente o proveedor ya cargado.
     * @return string
     */
    public static function findEmail($subject): string
    {
        if (self::isValidEmail($subject->email)) {
            return $subject->email;
        }

        // dirección predeterminada: la de facturación en clientes, la principal en proveedores
        $default = $subject->getDefaultAddress();
        if (self::isValidEmail($default->email)) {
            return $default->email;
        }

        // resto de direcciones y contactos del tercero
        foreach ($subject->getAddresses() as $address) {
            if (self::isValidEmail($address->email)) {
                return $address->email;
            }
        }

        return '';
    }

    /**
     * Indica si ya hay emails pendientes de enviar para un ejercicio y tipo de tercero.
     *
     * @param string $codejercicio ejercicio del envío.
     * @param string $type 'customers' o 'suppliers'.
     * @return bool
     */
    public static function hasPending(string $codejercicio, string $type): bool
    {
        $where = [
            Where::eq('name', self::eventName($type)),
            Where::eq('value', $codejercicio),
            Where::eq('done', false),
        ];
        return WorkEvent::count($where) > 0;
    }

    /**
     * Indica si la notificación por email de un tipo de tercero existe y está activada. Si no, deja un
     * aviso en el log.
     *
     * @param string $type 'customers' o 'suppliers'.
     * @return bool
     */
    public static function isNotificationEnabled(string $type): bool
    {
        $name = self::notificationName($type);
        $notification = new EmailNotification();
        if (false === $notification->load($name)) {
            Tools::log()->warning('email-notification-not-exists', ['%name%' => $name]);
            return false;
        }

        if (false === (bool)$notification->enabled) {
            Tools::log()->warning('email-notification-disabled', ['%name%' => $name]);
            return false;
        }

        return true;
    }

    /**
     * Devuelve el nombre de la notificación por email que corresponde a un tipo de tercero.
     *
     * @param string $type 'customers' o 'suppliers'.
     * @return string
     */
    public static function notificationName(string $type): string
    {
        return $type === self::TYPE_SUPPLIERS ? self::NOTIFICATION_SUPPLIERS : self::NOTIFICATION_CUSTOMERS;
    }

    /**
     * Encola un email por destinatario para un tipo de tercero. No encola nada si la notificación del
     * tipo no existe o está desactivada, si ya hay un envío pendiente del mismo ejercicio y tipo, o si
     * otro proceso está encolando a la vez.
     *
     * @param Ejercicio $exercise ejercicio declarado.
     * @param float     $amount importe mínimo del modelo, que se menciona en el texto del email.
     * @param array     $data filas del modelo (customersData o suppliersData).
     * @param string    $type 'customers' o 'suppliers'.
     * @return array ['status' => 'queued'|'pending'|'locked'|'disabled', 'queued' => int, 'noEmail' => filas sin email]
     */
    public static function queue(Ejercicio $exercise, float $amount, array $data, string $type): array
    {
        $result = ['status' => 'queued', 'queued' => 0, 'noEmail' => []];

        if (false === self::isNotificationEnabled($type)) {
            $result['status'] = 'disabled';
            return $result;
        }

        // bloqueamos para que dos peticiones simultáneas no encolen el mismo envío
        $lockName = 'modelo347-emails-' . $exercise->codejercicio . '-' . $type;
        if (false === Kernel::lock($lockName)) {
            $result['status'] = 'locked';
            return $result;
        }

        if (self::hasPending($exercise->codejercicio, $type)) {
            Kernel::unlock($lockName);
            $result['status'] = 'pending';
            return $result;
        }

        $recipients = self::recipients($data, $type);
        $result['noEmail'] = $recipients['noEmail'];
        $result['queued'] = self::queueRecipients($exercise, $amount, $recipients['recipients'], $type);

        Kernel::unlock($lockName);
        return $result;
    }

    /**
     * Encola un email por cada destinatario ya preparado con recipients(), sin más comprobaciones.
     * Lo usan queue() y el envío selectivo de Controller/Modelo347Email.
     *
     * @param Ejercicio $exercise ejercicio declarado.
     * @param float     $amount importe mínimo del modelo, que se menciona en el texto del email.
     * @param array     $recipients destinatarios con email (clave 'recipients' de recipients()).
     * @param string    $type 'customers' o 'suppliers'.
     * @return int número de emails encolados.
     */
    public static function queueRecipients(Ejercicio $exercise, float $amount, array $recipients, string $type): int
    {
        $params = [
            'amount' => $amount,
            'idempresa' => $exercise->idempresa,
            'year' => self::year($exercise),
        ];

        $queued = 0;
        foreach ($recipients as $recipient) {
            if (WorkQueue::send(self::eventName($type), $exercise->codejercicio, array_merge($params, $recipient))) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Prepara los destinatarios de un tipo de tercero a partir de los datos calculados del modelo.
     * Cada tercero recibe su propio email aunque comparta dirección con otro, porque sus importes son
     * distintos.
     *
     * @param array  $data filas del modelo (customersData o suppliersData), indexadas por código del tercero.
     * @param string $type 'customers' o 'suppliers'.
     * @return array ['recipients' => filas con email, 'noEmail' => filas sin email válido]
     */
    public static function recipients(array $data, string $type): array
    {
        $result = ['recipients' => [], 'noEmail' => []];
        $nameField = $type === self::TYPE_SUPPLIERS ? 'proveedor' : 'cliente';

        foreach ($data as $code => $row) {
            $subject = $type === self::TYPE_SUPPLIERS ? new Proveedor() : new Cliente();
            $email = $subject->load((string)$code) ? self::findEmail($subject) : '';

            $recipient = [
                'code' => (string)$code,
                'email' => $email,
                'name' => $row[$nameField] ?? '',
                'cifnif' => $row['cifnif'] ?? '',
                't1' => (float)($row['t1'] ?? 0),
                't2' => (float)($row['t2'] ?? 0),
                't3' => (float)($row['t3'] ?? 0),
                't4' => (float)($row['t4'] ?? 0),
                'total' => (float)($row['total'] ?? 0),
            ];

            if (empty($email)) {
                $result['noEmail'][] = $recipient;
                continue;
            }

            $result['recipients'][] = $recipient;
        }

        return $result;
    }

    /**
     * Devuelve el año natural del ejercicio, tomado de su fecha de inicio. El código del ejercicio no
     * siempre es el año (por ejemplo, 2025B).
     *
     * @param Ejercicio $exercise
     * @return string
     */
    public static function year(Ejercicio $exercise): string
    {
        return date('Y', strtotime($exercise->fechainicio));
    }

    /**
     * Indica si un email no está vacío y tiene un formato válido.
     *
     * @param string|null $email
     * @return bool
     */
    protected static function isValidEmail(?string $email): bool
    {
        return false === empty($email) && Validator::email($email);
    }
}
