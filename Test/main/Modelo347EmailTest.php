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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Modelo347Email;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la preparación y el encolado de los emails del Modelo 347 (Lib/Modelo347Email).
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo347EmailTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    /** Código del ejercicio ficticio con el que se encolan los eventos de prueba. */
    const TEST_EXERCISE = 'T347';

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
    }

    /**
     * Comprueba que el email se busca en la ficha, después en la dirección predeterminada y después en
     * cualquier otra dirección del tercero.
     */
    public function testFindEmail(): void
    {
        // email en la ficha
        $customer = $this->getCustomer('ficha@example.com');
        $this->assertEquals('ficha@example.com', Modelo347Email::findEmail($customer));

        // sin email en la ficha: se usa la dirección de facturación
        $customer2 = $this->getCustomer('');
        $billing = $customer2->getDefaultAddress();
        $billing->email = 'facturacion@example.com';
        $this->assertTrue($billing->save(), 'billing-address-cant-save');
        $customer2->load($customer2->codcliente);
        $this->assertEquals('', $customer2->email);
        $this->assertEquals('facturacion@example.com', Modelo347Email::findEmail($customer2));

        // proveedor con email inválido en la ficha y sin email en sus direcciones
        $supplier = $this->getSupplier('no-es-un-email');
        $this->assertEquals('', Modelo347Email::findEmail($supplier));

        $this->assertTrue($customer->delete());
        $this->assertTrue($customer2->delete());
        $this->assertTrue($supplier->delete());
    }

    /**
     * Comprueba que se encola un evento por destinatario, escalonado en el tiempo y con el año del
     * ejercicio, y que un segundo envío no se encola mientras el primero siga pendiente.
     */
    public function testQueue(): void
    {
        $this->deleteTestEvents();

        $supplier1 = $this->getSupplier('uno@example.com');
        $supplier2 = $this->getSupplier('dos@example.com');
        $data = [
            $supplier1->codproveedor => $this->getRow('proveedor', $supplier1->nombre, 4000),
            $supplier2->codproveedor => $this->getRow('proveedor', $supplier2->nombre, 5000),
        ];

        // el código del ejercicio no es el año: el email debe usar el año de la fecha de inicio
        $exercise = new Ejercicio();
        $exercise->codejercicio = self::TEST_EXERCISE;
        $exercise->fechainicio = '01-01-2025';
        $exercise->idempresa = (int)Tools::settings('default', 'idempresa');
        $this->assertEquals('2025', Modelo347Email::year($exercise));

        $result = Modelo347Email::queue($exercise, 3005.06, $data, Modelo347Email::TYPE_SUPPLIERS);
        $this->assertEquals('queued', $result['status']);
        $this->assertEquals(2, $result['queued']);
        $this->assertTrue(Modelo347Email::hasPending(self::TEST_EXERCISE, Modelo347Email::TYPE_SUPPLIERS));
        $this->assertFalse(Modelo347Email::hasPending(self::TEST_EXERCISE, Modelo347Email::TYPE_CUSTOMERS));

        $events = WorkEvent::all([Where::eq('value', self::TEST_EXERCISE)], ['id' => 'ASC']);
        $this->assertCount(2, $events);
        $this->assertEquals(Modelo347Email::EVENT_SUPPLIERS, $events[0]->name);
        $this->assertEquals('uno@example.com', $events[0]->param('email'));
        $this->assertEquals('2025', $events[0]->param('year'));
        $this->assertEquals($exercise->idempresa, $events[0]->param('idempresa'));
        $this->assertEquals('dos@example.com', $events[1]->param('email'));

        // los emails se programan escalonados: el primero ya y el segundo SEND_INTERVAL segundos después
        $first = strtotime($events[0]->creation_date);
        $this->assertLessThanOrEqual(time() + 1, $first);
        $this->assertEquals(Modelo347Email::SEND_INTERVAL, strtotime($events[1]->creation_date) - $first);

        // un nuevo envío empezaría a continuación del último email pendiente
        $this->assertGreaterThanOrEqual(2 * Modelo347Email::SEND_INTERVAL - 1, Modelo347Email::firstDelay());

        // segundo envío con el primero pendiente: no se encola nada
        $result2 = Modelo347Email::queue($exercise, 3005.06, $data, Modelo347Email::TYPE_SUPPLIERS);
        $this->assertEquals('pending', $result2['status']);
        $this->assertEquals(0, $result2['queued']);
        $this->assertCount(2, WorkEvent::all([Where::eq('value', self::TEST_EXERCISE)]));

        $this->deleteTestEvents();
        $this->assertTrue($supplier1->delete());
        $this->assertTrue($supplier2->delete());
    }

    /**
     * Comprueba que no se encola nada si la notificación por email del tipo está desactivada y que,
     * al volver a activarla, se encola con normalidad.
     */
    public function testQueueNotificationDisabled(): void
    {
        $this->deleteTestEvents();

        // la notificación la crea Init::update() al instalar el plugin
        $notification = new EmailNotification();
        $this->assertTrue($notification->load(Modelo347Email::NOTIFICATION_CUSTOMERS), 'notification-not-found');
        $notification->enabled = false;
        $this->assertTrue($notification->save(), 'notification-cant-save');

        $customer = $this->getCustomer('cliente@example.com');
        $data = [$customer->codcliente => $this->getRow('cliente', $customer->nombre, 4000)];

        $exercise = new Ejercicio();
        $exercise->codejercicio = self::TEST_EXERCISE;
        $exercise->fechainicio = '01-01-2025';
        $exercise->idempresa = (int)Tools::settings('default', 'idempresa');

        $result = Modelo347Email::queue($exercise, 3005.06, $data, Modelo347Email::TYPE_CUSTOMERS);
        $this->assertEquals('disabled', $result['status']);
        $this->assertEquals(0, $result['queued']);
        $this->assertCount(0, WorkEvent::all([Where::eq('value', self::TEST_EXERCISE)]));

        // reactivamos la notificación: ahora sí se encola
        $notification->enabled = true;
        $this->assertTrue($notification->save(), 'notification-cant-save');
        $result2 = Modelo347Email::queue($exercise, 3005.06, $data, Modelo347Email::TYPE_CUSTOMERS);
        $this->assertEquals('queued', $result2['status']);
        $this->assertEquals(1, $result2['queued']);

        $this->deleteTestEvents();
        $this->assertTrue($customer->delete());
    }

    /**
     * Comprueba que dos terceros con el mismo email reciben cada uno su email y que los que no tienen
     * email se devuelven aparte.
     */
    public function testRecipients(): void
    {
        $customer1 = $this->getCustomer('compartido@example.com');
        $customer2 = $this->getCustomer('compartido@example.com');
        $customer3 = $this->getCustomer('');

        $data = [
            $customer1->codcliente => $this->getRow('cliente', $customer1->nombre, 4000),
            $customer2->codcliente => $this->getRow('cliente', $customer2->nombre, 5000),
            $customer3->codcliente => $this->getRow('cliente', $customer3->nombre, 6000),
        ];
        $result = Modelo347Email::recipients($data, Modelo347Email::TYPE_CUSTOMERS);

        $this->assertCount(2, $result['recipients']);
        $this->assertEquals('compartido@example.com', $result['recipients'][0]['email']);
        $this->assertEquals('compartido@example.com', $result['recipients'][1]['email']);
        $this->assertEquals(4000, $result['recipients'][0]['total']);
        $this->assertEquals(5000, $result['recipients'][1]['total']);
        $this->assertEquals($customer1->nombre, $result['recipients'][0]['name']);

        $this->assertCount(1, $result['noEmail']);
        $this->assertEquals($customer3->nombre, $result['noEmail'][0]['name']);

        $this->assertTrue($customer1->delete());
        $this->assertTrue($customer2->delete());
        $this->assertTrue($customer3->delete());
    }

    /**
     * Borra los eventos de la cola creados por las pruebas.
     */
    private function deleteTestEvents(): void
    {
        foreach (WorkEvent::all([Where::eq('value', self::TEST_EXERCISE)]) as $event) {
            $event->delete();
        }
    }

    /**
     * Crea y guarda un cliente de prueba con el email indicado.
     *
     * @param string $email
     * @return Cliente
     */
    private function getCustomer(string $email): Cliente
    {
        $customer = new Cliente();
        $customer->nombre = 'Cliente 347 ' . mt_rand(1, 99999);
        $customer->cifnif = 'B' . mt_rand(1000000, 9999999);
        $customer->email = $email;
        $this->assertTrue($customer->save(), 'customer-cant-save');
        return $customer;
    }

    /**
     * Devuelve una fila con el formato de customersData o suppliersData del modelo.
     *
     * @param string $nameField 'cliente' o 'proveedor'.
     * @param string $name
     * @param float  $total
     * @return array
     */
    private function getRow(string $nameField, string $name, float $total): array
    {
        return [
            $nameField => $name,
            'cifnif' => 'B12345678',
            't1' => $total,
            't2' => 0,
            't3' => 0,
            't4' => 0,
            'total' => $total,
        ];
    }

    /**
     * Crea y guarda un proveedor de prueba con el email indicado, sin validar su formato.
     *
     * @param string $email
     * @return Proveedor
     */
    private function getSupplier(string $email): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->nombre = 'Proveedor 347 ' . mt_rand(1, 99999);
        $supplier->cifnif = 'B' . mt_rand(1000000, 9999999);
        if (Validator::email($email)) {
            $supplier->email = $email;
        }
        $this->assertTrue($supplier->save(), 'supplier-cant-save');

        // el modelo no guarda emails inválidos, así que estos se asignan sin guardar
        $supplier->email = $email;
        return $supplier;
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
