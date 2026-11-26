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

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Modelo347;
use FacturaScripts\Dinamic\Lib\Modelo347Api;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PagoCliente;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del endpoint de la API del Modelo 347 (Lib/Modelo347Api) y del cálculo compartido con la pantalla.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo347ApiTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    /**
     * Prepara la configuración por defecto y las tablas de recibos y pagos.
     */
    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();

        // creamos las tablas de recibos y pagos antes de guardar facturas: al guardarlas, el core genera los
        // recibos dentro de una transacción y no se pueden crear tablas dentro de ella
        new ReciboCliente();
        new PagoCliente();
    }

    /**
     * Comprueba los errores de empresa, ejercicio, año y parámetros propios.
     */
    public function testErrors(): void
    {
        $result = Modelo347Api::build(['codejercicio' => 'ZZZZ']);
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('exercise-not-found', $result['body']['message']);
        $this->assertEquals('error', $result['body']['status']);

        $result = Modelo347Api::build(['idempresa' => '999999']);
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('company-not-found', $result['body']['message']);

        $result = Modelo347Api::build(['idempresa' => (string)Tools::settings('default', 'idempresa'), 'anio' => '1901']);
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('exercise-not-found', $result['body']['message']);

        $result = Modelo347Api::build(['anio' => '26']);
        $this->assertEquals(400, $result['code']);
        $this->assertEquals('invalid-year', $result['body']['message']);

        foreach (['importe' => 'abc', 'origen' => 'foo', 'agrupar' => 'bar', 'excluir_irpf' => 'quizas'] as $key => $value) {
            $result = Modelo347Api::build([$key => $value]);
            $this->assertEquals(400, $result['code'], $key);
            $this->assertEquals('invalid-parameter', $result['body']['message'], $key);
            $this->assertEquals($key, $result['body']['parameter'], $key);
        }
    }

    /**
     * Comprueba los periodos: solo anual, con sus alias, y anual si no se indica.
     */
    public function testPeriods(): void
    {
        $this->assertEquals('ANUAL', Modelo347Api::normalizePeriod('annual'));
        $this->assertEquals('ANUAL', Modelo347Api::normalizePeriod(' 0a '));
        $this->assertEquals('T1', Modelo347Api::normalizePeriod('1t'));
        $this->assertEquals('03', Modelo347Api::normalizePeriod('3'));

        foreach (['T1', '1T', '03', 'XX'] as $periodo) {
            $result = Modelo347Api::build(['periodo' => $periodo]);
            $this->assertEquals(400, $result['code'], $periodo);
            $this->assertEquals('invalid-period', $result['body']['message']);
            $this->assertEquals(['ANUAL'], $result['body']['allowed']);
        }

        // creamos una factura para asegurar que existe el ejercicio del año actual
        [$customer, $invoice] = $this->createCustomerInvoice(100);
        foreach ([null, '', 'anual', 'Y', 'A'] as $periodo) {
            $params = ['codejercicio' => $invoice->codejercicio];
            if (null !== $periodo) {
                $params['periodo'] = $periodo;
            }
            $result = Modelo347Api::build($params);
            $this->assertEquals(200, $result['code'], (string)$periodo);
            $this->assertEquals('ANUAL', $result['body']['periodo']);
        }
        $this->deleteCustomerInvoice($customer, $invoice);
    }

    /**
     * Comprueba que se puede resolver el ejercicio por empresa y año, y que da el mismo que por código.
     */
    public function testResolveExerciseByCompanyAndYear(): void
    {
        [$customer, $invoice] = $this->createCustomerInvoice(4000);

        $exercise = new Ejercicio();
        $this->assertTrue($exercise->load($invoice->codejercicio));
        $year = date('Y', strtotime($exercise->fechainicio));

        $byCode = Modelo347Api::build(['codejercicio' => $invoice->codejercicio]);
        $byYear = Modelo347Api::build(['idempresa' => (string)$invoice->idempresa, 'anio' => $year]);
        $this->assertEquals(200, $byCode['code']);
        $this->assertEquals(200, $byYear['code']);
        $this->assertEquals($invoice->codejercicio, $byYear['body']['ejercicio']['codejercicio']);
        $this->assertEquals($byCode['body']['resumen'], $byYear['body']['resumen']);

        $this->deleteCustomerInvoice($customer, $invoice);
    }

    /**
     * Comprueba la respuesta completa con un cliente por encima del importe mínimo.
     */
    public function testResponseWithCustomer(): void
    {
        [$customer, $invoice] = $this->createCustomerInvoice(4000);

        $result = Modelo347Api::build(['codejercicio' => $invoice->codejercicio]);
        $this->assertEquals(200, $result['code']);

        $body = $result['body'];
        $this->assertEquals('347', $body['modelo']);
        $this->assertArrayNotHasKey('modelo_anual', $body);
        $this->assertEquals('ANUAL', $body['periodo']);
        $this->assertNull($body['resultado']);
        $this->assertEquals((int)$invoice->idempresa, $body['empresa']['idempresa']);
        $this->assertEquals($invoice->codejercicio, $body['ejercicio']['codejercicio']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $body['fechainicio']);
        $this->assertEquals(
            ['importe' => 3005.06, 'origen' => 'facturas', 'agrupar' => 'tercero', 'excluir_irpf' => false],
            $body['parametros']
        );
        $this->assertGreaterThan(0, $body['plugin_version']);

        // el cliente aparece en el detalle con el total de su factura
        $row = $this->findRow($body['detalle']['clientes'], $customer->codcliente);
        $this->assertNotNull($row, 'el cliente debe aparecer en el detalle');
        $this->assertEquals(round($invoice->total, 2), $row['total']);
        $this->assertEquals($customer->cifnif, $row['cifnif']);
        foreach (['codigo', 'nombre', 'cifnif', 'codpais', 'codpostal', 'ciudad', 'provincia', 't1', 't2', 't3', 't4', 'total'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }

        // resumen y casillas coherentes con el detalle
        $this->assertEquals(count($body['detalle']['clientes']), $body['resumen']['num_clientes']);
        $this->assertEquals(
            $body['resumen']['num_clientes'] + $body['resumen']['num_proveedores'],
            $body['casillas']['01']
        );
        $this->assertEquals(
            round($body['resumen']['total_clientes'] + $body['resumen']['total_proveedores'], 2),
            $body['casillas']['02']
        );
        $this->assertIsArray($body['avisos']);

        // con un importe mínimo superior a la factura, el cliente ya no aparece
        $result2 = Modelo347Api::build(['codejercicio' => $invoice->codejercicio, 'importe' => '999999999']);
        $this->assertEquals(200, $result2['code']);
        $this->assertNull($this->findRow($result2['body']['detalle']['clientes'], $customer->codcliente));

        // el endpoint da las mismas cifras que el cálculo de la pantalla
        $model347 = new Modelo347($invoice->codejercicio);
        $model347->calculate();
        $this->assertEquals(round($model347->customersTotals['total'], 2), $body['resumen']['total_clientes']);
        $this->assertEquals(count($model347->customersData), $body['resumen']['num_clientes']);

        $this->deleteCustomerInvoice($customer, $invoice);
    }

    /**
     * Comprueba que cada empresa solo ve los terceros de su ejercicio, aunque ambas
     * tengan facturas en el mismo año.
     */
    public function testTwoCompaniesSameYear(): void
    {
        // factura de la empresa predeterminada
        [$customer1, $invoice1] = $this->createCustomerInvoice(4000);

        // segunda empresa con una factura en el mismo año
        $company2 = new Empresa();
        $company2->nombre = 'Company 347 ' . mt_rand(1, 9999);
        $company2->nombrecorto = 'C347-' . mt_rand(1, 9999);
        $this->assertTrue($company2->save(), 'company-cant-save');
        $warehouse = new Almacen();
        $this->assertTrue($warehouse->loadWhereEq('idempresa', $company2->idempresa), 'warehouse-not-found');
        [$customer2, $invoice2] = $this->createCustomerInvoice(5000, $warehouse->codalmacen);
        $this->assertEquals($company2->idempresa, $invoice2->idempresa);
        $this->assertNotEquals($invoice1->codejercicio, $invoice2->codejercicio);

        // por empresa y año: cada una ve solo a su cliente
        $year = date('Y');
        $result1 = Modelo347Api::build(['idempresa' => (string)$invoice1->idempresa, 'anio' => $year]);
        $result2 = Modelo347Api::build(['idempresa' => (string)$company2->idempresa, 'anio' => $year]);
        $this->assertEquals(200, $result1['code']);
        $this->assertEquals(200, $result2['code']);
        $this->assertEquals($invoice2->codejercicio, $result2['body']['ejercicio']['codejercicio']);
        $this->assertEquals($company2->idempresa, $result2['body']['empresa']['idempresa']);

        $this->assertNotNull($this->findRow($result1['body']['detalle']['clientes'], $customer1->codcliente));
        $this->assertNull($this->findRow($result1['body']['detalle']['clientes'], $customer2->codcliente));
        $this->assertNotNull($this->findRow($result2['body']['detalle']['clientes'], $customer2->codcliente));
        $this->assertNull($this->findRow($result2['body']['detalle']['clientes'], $customer1->codcliente));
        $this->assertCount(1, $result2['body']['detalle']['clientes']);

        // limpiamos
        $this->deleteCustomerInvoice($customer2, $invoice2);
        $this->deleteCustomerInvoice($customer1, $invoice1);
        $exercise2 = new Ejercicio();
        if ($exercise2->load($invoice2->codejercicio)) {
            $this->assertTrue($exercise2->delete(), 'exercise-cant-delete');
        }
        $this->assertTrue($company2->delete(), 'company-cant-delete');
    }

    /**
     * Crea un cliente y una factura de hoy con una línea del importe indicado.
     *
     * @param float  $amount     precio unitario de la línea.
     * @param string $codalmacen almacén (y por tanto empresa) de la factura; vacío para el predeterminado.
     *
     * @return array [Cliente, FacturaCliente]
     */
    private function createCustomerInvoice(float $amount, string $codalmacen = ''): array
    {
        $customer = new Cliente();
        $customer->cifnif = 'B' . mt_rand(1000000, 9999999);
        $customer->nombre = 'Cliente 347 ' . mt_rand(1, 99999);
        $customer->razonsocial = 'Empresa 347 ' . mt_rand(1, 99999);
        $this->assertTrue($customer->save(), 'cant-create-customer');

        $invoice = new FacturaCliente();
        $invoice->setSubject($customer);
        if ($codalmacen) {
            $invoice->codalmacen = $codalmacen;
            $this->assertTrue($invoice->setDate(date('d-m-Y'), $invoice->hora), 'cant-set-date');
        }
        $this->assertTrue($invoice->save(), 'cant-create-invoice');

        $line = $invoice->getNewLine();
        $line->descripcion = 'Test 347';
        $line->cantidad = 1;
        $line->pvpunitario = $amount;
        $this->assertTrue($line->save(), 'cant-create-line');

        $lines = $invoice->getLines();
        $this->assertTrue(Calculator::calculate($invoice, $lines, true), 'cant-calculate-invoice');

        return [$customer, $invoice];
    }

    /**
     * Elimina la factura, el cliente y su dirección creados por createCustomerInvoice().
     *
     * @param Cliente        $customer cliente.
     * @param FacturaCliente $invoice  factura.
     */
    private function deleteCustomerInvoice(Cliente $customer, FacturaCliente $invoice): void
    {
        $this->assertTrue($invoice->delete(), 'cant-delete-invoice');
        $this->assertTrue($customer->getDefaultAddress()->delete(), 'cant-delete-contact');
        $this->assertTrue($customer->delete(), 'cant-delete-customer');
    }

    /**
     * Busca una fila del detalle por código de tercero.
     *
     * @param array  $rows filas del detalle.
     * @param string $code código del cliente o proveedor.
     *
     * @return array|null
     */
    private function findRow(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ($row['codigo'] === $code) {
                return $row;
            }
        }
        return null;
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
