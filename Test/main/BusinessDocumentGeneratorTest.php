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
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Comprueba que las facturas generadas desde pedidos o albaranes heredan
 * excluir347 de la ficha del cliente o proveedor.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
final class BusinessDocumentGeneratorTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    /** @var array Registros creados en cada test, en orden de borrado. */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    /**
     * Factura de cliente generada desde un pedido de un cliente excluido del 347.
     */
    public function testInvoiceFromCustomerOrderInheritsExclusion(): void
    {
        $customer = $this->createCustomer(true);
        $order = $this->createDocument(new PedidoCliente(), $customer);

        $invoice = $this->generate($order, 'FacturaCliente');
        $this->assertTrue($invoice->excluir347, 'invoice-must-inherit-excluir347');
    }

    /**
     * Factura de cliente generada desde un pedido de un cliente no excluido.
     */
    public function testInvoiceFromCustomerOrderNotExcluded(): void
    {
        $customer = $this->createCustomer(false);
        $order = $this->createDocument(new PedidoCliente(), $customer);

        $invoice = $this->generate($order, 'FacturaCliente');
        $this->assertFalse($invoice->excluir347, 'invoice-must-not-be-excluded');
    }

    /**
     * El valor indicado expresamente en las propiedades de la generación prevalece sobre la ficha.
     */
    public function testExplicitPropertyIsRespected(): void
    {
        $customer = $this->createCustomer(true);
        $order = $this->createDocument(new PedidoCliente(), $customer);

        $invoice = $this->generate($order, 'FacturaCliente', ['excluir347' => false]);
        $this->assertFalse($invoice->excluir347, 'explicit-property-must-be-respected');
    }

    /**
     * Factura de proveedor generada desde un albarán de un proveedor excluido del 347.
     */
    public function testInvoiceFromSupplierDeliveryNoteInheritsExclusion(): void
    {
        $supplier = $this->createSupplier(true);
        $deliveryNote = $this->createDocument(new AlbaranProveedor(), $supplier);

        $invoice = $this->generate($deliveryNote, 'FacturaProveedor');
        $this->assertTrue($invoice->excluir347, 'invoice-must-inherit-excluir347');
    }

    protected function tearDown(): void
    {
        // borramos en orden inverso de creación; cada borrado aislado para no dejar restos
        foreach (array_reverse($this->created) as $item) {
            try {
                if ($item->exists()) {
                    $item->delete();
                }
            } catch (Throwable $e) {
                // ignoramos el error para seguir limpiando el resto
            }
        }
        $this->created = [];

        $this->logErrors();
    }

    /**
     * Crea un cliente con el valor de excluir347 indicado.
     *
     * @param bool $exclude
     *
     * @return Cliente
     */
    private function createCustomer(bool $exclude): Cliente
    {
        $customer = new Cliente();
        $customer->cifnif = 'B' . mt_rand(1, 999999);
        $customer->nombre = 'Test 347 ' . mt_rand(1, 99999);
        $customer->excluir347 = $exclude;
        $this->assertTrue($customer->save(), 'cant-create-customer');
        $this->created[] = $customer;
        $this->created[] = $customer->getDefaultAddress();
        return $customer;
    }

    /**
     * Crea un proveedor con el valor de excluir347 indicado.
     *
     * @param bool $exclude
     *
     * @return Proveedor
     */
    private function createSupplier(bool $exclude): Proveedor
    {
        $supplier = new Proveedor();
        $supplier->cifnif = 'B' . mt_rand(1, 999999);
        $supplier->nombre = 'Test 347 ' . mt_rand(1, 99999);
        $supplier->excluir347 = $exclude;
        $this->assertTrue($supplier->save(), 'cant-create-supplier');
        $this->created[] = $supplier;
        $this->created[] = $supplier->getDefaultAddress();
        return $supplier;
    }

    /**
     * Guarda el documento con una línea para el sujeto indicado.
     *
     * @param BusinessDocument $doc
     * @param Cliente|Proveedor $subject
     *
     * @return BusinessDocument
     */
    private function createDocument(BusinessDocument $doc, $subject): BusinessDocument
    {
        $this->assertTrue($doc->setSubject($subject), 'cant-set-subject');
        $this->assertTrue($doc->save(), 'cant-create-document');
        $this->created[] = $doc;

        $line = $doc->getNewLine(['descripcion' => 'Test 347', 'cantidad' => 1, 'pvpunitario' => 100]);
        $this->assertTrue($line->save(), 'cant-create-line');

        $lines = $doc->getLines();
        $this->assertTrue(Calculator::calculate($doc, $lines, true), 'cant-calculate-document');
        return $doc;
    }

    /**
     * Genera el documento de destino y lo devuelve recargado de la base de datos.
     *
     * @param BusinessDocument $prototype
     * @param string $newClass
     * @param array $properties
     *
     * @return BusinessDocument
     */
    private function generate(BusinessDocument $prototype, string $newClass, array $properties = []): BusinessDocument
    {
        $generator = new BusinessDocumentGenerator();
        $this->assertTrue($generator->generate($prototype, $newClass, [], [], $properties), 'cant-generate-' . $newClass);

        $newDoc = $generator->getLastDocs()[0];
        $this->created[] = $newDoc;
        $this->assertTrue($newDoc->reload(), 'cant-reload-' . $newClass);
        return $newDoc;
    }
}
