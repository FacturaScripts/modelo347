<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Dinamic\Lib\Export\XLSExport;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Description of Modelo347
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Modelo347 extends Controller
{

    /**
     *
     * @var float
     */
    public $amount = 3005.06;

    /**
     *
     * @var string
     */
    public $codejercicio;

    /**
     *
     * @var array
     */
    public $customersData = [];

    /**
     *
     * @var array
     */
    public $customersTotals = [];

    /**
     *
     * @var string
     */
    public $examine = 'invoices';

    /**
     *
     * @var bool
     */
    public $excludeIrpf = false;

    /**
     *
     * @var array
     */
    public $suppliersData = [];

    /**
     *
     * @var array
     */
    public $suppliersTotals = [];

    /**
     *
     * @return array
     */
    public function allExamine()
    {
        return ['invoices'];
    }

    /**
     *
     * @return Ejercicio[]
     */
    public function allExercises()
    {
        $ejercicio = new Ejercicio();
        return $ejercicio->all([], ['nombre' => 'DESC']);
    }

    /**
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'model-347';
        $data['icon'] = 'fas fa-book';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->request->get('action', '');
        switch ($action) {
            case 'download':
                $this->defaultAction();
                $this->downloadAction();
                break;

            default:
                $this->defaultAction();
        }
    }

    protected function defaultAction()
    {
        /// get last exercise code
        $codejercicio = null;
        $exerciseModel = new Ejercicio();
        foreach ($exerciseModel->all([], ['fechainicio' => 'DESC'], 0, 0) as $exe) {
            if ($exe->isOpened()) {
                $codejercicio = $exe->codejercicio;
                break;
            }
        }

        $this->amount = (float)$this->request->request->get('amount', $this->amount);
        $this->codejercicio = $this->request->request->get('codejercicio', $codejercicio);
        $this->examine = $this->request->request->get('examine', $this->examine);
        $this->excludeIrpf = (bool)$this->request->request->get('excludeirpf', $this->excludeIrpf);

        $this->loadCustomersData();
        $this->loadSuppliersData();
    }

    protected function downloadAction()
    {
        $this->setTemplate(false);
        $xlsExport = new XLSExport();
        $xlsExport->newDoc($this->toolBox()->i18n()->trans('model-347'), 0, '');

        $i18n = $this->toolBox()->i18n();

        /// customers data
        $customersHeaders = [
            'cifnif' => $i18n->trans('cifnif'),
            'cliente' => $i18n->trans('customer'),
            'codpostal' => $i18n->trans('zip-code'),
            'ciudad' => $i18n->trans('city'),
            'provincia' => $i18n->trans('province'),
            't1' => $i18n->trans('first-trimester'),
            't2' => $i18n->trans('second-trimester'),
            't3' => $i18n->trans('third-trimester'),
            't4' => $i18n->trans('fourth-trimester'),
            'total' => $i18n->trans('total')
        ];
        $rows1 = $this->customersData;
        $rows1[] = $this->customersTotals;
        $xlsExport->addTablePage($customersHeaders, $rows1);

        /// suppliers data
        $suppliersHeaders = [
            'cifnif' => $i18n->trans('cifnif'),
            'proveedor' => $i18n->trans('supplier'),
            'codpostal' => $i18n->trans('zip-code'),
            'ciudad' => $i18n->trans('city'),
            'provincia' => $i18n->trans('province'),
            't1' => $i18n->trans('first-trimester'),
            't2' => $i18n->trans('second-trimester'),
            't3' => $i18n->trans('third-trimester'),
            't4' => $i18n->trans('fourth-trimester'),
            'total' => $i18n->trans('total')
        ];
        $rows2 = $this->suppliersData;
        $rows2[] = $this->suppliersTotals;
        $xlsExport->addTablePage($suppliersHeaders, $rows2);

        $xlsExport->show($this->response);
    }

    /**
     *
     * @return array
     */
    protected function getCustomersDataInvoices(): array
    {
        $sql = \strtolower(FS_DB_TYPE) == 'postgresql' ?
            "SELECT codcliente, to_char(fecha,'FMMM') as mes, sum(total) as total FROM facturascli"
            . " WHERE codejercicio = " . $this->dataBase->var2str($this->codejercicio) :
            "SELECT codcliente, DATE_FORMAT(fecha, '%m') as mes, sum(total) as total FROM facturascli"
            . " WHERE codejercicio = " . $this->dataBase->var2str($this->codejercicio);

        if ($this->excludeIrpf) {
            $sql .= " AND irpf = 0";
        }

        $sql .= " GROUP BY codcliente, mes ORDER BY codcliente;";

        $items = [];
        foreach ($this->dataBase->select($sql) as $row) {
            $codcliente = $row['codcliente'];
            if (isset($items[$codcliente])) {
                $this->groupTotals($items[$codcliente], $row);
                continue;
            }

            $items[$codcliente] = [
                'cifnif' => '',
                'cliente' => $row['codcliente'],
                'codpostal' => '',
                'ciudad' => '',
                'provincia' => '',
                't1' => 0.0,
                't2' => 0.0,
                't3' => 0.0,
                't4' => 0.0,
                'total' => 0.0
            ];

            $cliente = new Cliente();
            if ($cliente->loadFromCode($codcliente)) {
                $dir = $cliente->getDefaultAddress();
                $items[$codcliente]['cifnif'] = $cliente->cifnif;
                $items[$codcliente]['cliente'] = $cliente->razonsocial;
                $items[$codcliente]['codpostal'] = $dir->codpostal;
                $items[$codcliente]['ciudad'] = $dir->ciudad;
                $items[$codcliente]['provincia'] = $dir->provincia;
            }

            $this->groupTotals($items[$codcliente], $row);
        }

        return $items;
    }

    /**
     *
     * @return array
     */
    protected function getSuppliersDataInvoices(): array
    {
        $sql = \strtolower(FS_DB_TYPE) == 'postgresql' ?
            "SELECT codproveedor, to_char(fecha,'FMMM') as mes, sum(total) as total FROM facturasprov"
            . " WHERE codejercicio = " . $this->dataBase->var2str($this->codejercicio) :
            "SELECT codproveedor, DATE_FORMAT(fecha, '%m') as mes, sum(total) as total FROM facturasprov"
            . " WHERE codejercicio = " . $this->dataBase->var2str($this->codejercicio);

        if ($this->excludeIrpf) {
            $sql .= " AND irpf = 0";
        }

        $sql .= " GROUP BY codproveedor, mes ORDER BY codproveedor;";

        $items = [];
        foreach ($this->dataBase->select($sql) as $row) {
            $codproveedor = $row['codproveedor'];
            if (isset($items[$codproveedor])) {
                $this->groupTotals($items[$codproveedor], $row);
                continue;
            }

            $items[$codproveedor] = [
                'cifnif' => '',
                'proveedor' => $row['codproveedor'],
                'codpostal' => '',
                'ciudad' => '',
                'provincia' => '',
                't1' => 0.0,
                't2' => 0.0,
                't3' => 0.0,
                't4' => 0.0,
                'total' => 0.0
            ];

            $proveedor = new Proveedor();
            if ($proveedor->loadFromCode($codproveedor)) {
                $dir = $proveedor->getDefaultAddress();
                $items[$codproveedor]['cifnif'] = $proveedor->cifnif;
                $items[$codproveedor]['proveedor'] = $proveedor->razonsocial;
                $items[$codproveedor]['codpostal'] = $dir->codpostal;
                $items[$codproveedor]['ciudad'] = $dir->ciudad;
                $items[$codproveedor]['provincia'] = $dir->provincia;
            }

            $this->groupTotals($items[$codproveedor], $row);
        }

        return $items;
    }

    /**
     *
     * @param array $item
     * @param array $row
     */
    protected function groupTotals(&$item, $row)
    {
        if (\in_array($row['mes'], ['1', '2', '3', '01', '02', '03'])) {
            $item['t1'] += (float)$row['total'];
        } elseif (\in_array($row['mes'], ['4', '5', '6', '04', '05', '06'])) {
            $item['t2'] += (float)$row['total'];
        } elseif (\in_array($row['mes'], ['7', '8', '9', '07', '08', '09'])) {
            $item['t3'] += (float)$row['total'];
        } else {
            $item['t4'] += (float)$row['total'];
        }

        $item['total'] += (float)$row['total'];
    }

    protected function loadCustomersData()
    {
        $this->customersData = $this->getCustomersDataInvoices();

        /// exclude if total lower than amount
        foreach ($this->customersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->customersData[$key]);
            }
        }

        /// totals
        $this->customersTotals = [
            'cifnif' => '',
            'cliente' => '',
            'codpostal' => '',
            'ciudad' => '',
            'provincia' => '',
            't1' => 0.0,
            't2' => 0.0,
            't3' => 0.0,
            't4' => 0.0,
            'total' => 0.0
        ];
        foreach ($this->customersData as $row) {
            $this->customersTotals['t1'] += $row['t1'];
            $this->customersTotals['t2'] += $row['t2'];
            $this->customersTotals['t3'] += $row['t3'];
            $this->customersTotals['t4'] += $row['t4'];
            $this->customersTotals['total'] += $row['total'];
        }
    }

    protected function loadSuppliersData()
    {
        $this->suppliersData = $this->getSuppliersDataInvoices();

        /// exclude if total lower than amount
        foreach ($this->suppliersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->suppliersData[$key]);
            }
        }

        /// totals
        $this->suppliersTotals = [
            'cifnif' => '',
            'proveedor' => '',
            'codpostal' => '',
            'ciudad' => '',
            'provincia' => '',
            't1' => 0.0,
            't2' => 0.0,
            't3' => 0.0,
            't4' => 0.0,
            'total' => 0.0
        ];
        foreach ($this->suppliersData as $row) {
            $this->suppliersTotals['t1'] += $row['t1'];
            $this->suppliersTotals['t2'] += $row['t2'];
            $this->suppliersTotals['t3'] += $row['t3'];
            $this->suppliersTotals['t4'] += $row['t4'];
            $this->suppliersTotals['total'] += $row['total'];
        }
    }
}
