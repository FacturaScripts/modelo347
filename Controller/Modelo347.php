<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2020-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Dinamic\Lib\Export\XLSExport;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\Modelo347\Lib\Txt347Export;

/**
 * Description of Modelo347
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Modelo347 extends Controller
{
    /** @var string */
    public $activetab = 'customers';

    /** @var float */
    public $amount = 3005.06;

    /** @var string */
    public $codejercicio;

    /** @var array */
    public $customersData = [];

    /** @var array */
    public $customersTotals = [];

    /** @var string */
    public $examine = 'invoices';

    /** @var bool */
    public $excludeIrpf = false;

    /** @var string */
    public $grouping = 'customer-supplier';

    /** @var array */
    public $suppliersData = [];

    /** @var array */
    public $suppliersTotals = [];

    public function allExamine(): array
    {
        return ['accounting', 'invoices'];
    }

    /**
     * @param int|null $idempresa
     * @return Ejercicio[]
     */
    public function allExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $ejercicio) {
            if ($ejercicio->idempresa === $idempresa) {
                $list[] = $ejercicio;
            }
        }
        return $list;
    }

    public function allGroupBy(): array
    {
        return ['cifnif', 'customer-supplier'];
    }

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

        $this->activetab = $this->request->request->get('activetab', $this->activetab);
        $action = $this->request->request->get('action', '');

        $this->defaultAction();
        switch ($action) {
            case 'download-excel':
                $this->downloadExcelAction();
                break;

            case 'download-txt':
                $this->downloadTxtAction();
                break;
        }
    }

    protected function checkAddress(array $data, string $type): void
    {
        $context = [
            '%cifnif%' => $data['cifnif'],
            '%name%' => $data['cliente'] ?? $data['proveedor'] ?? '',
            '%type%' => $this->toolBox()->i18n()->trans($type),
        ];

        if (empty($data['provincia'])) {
            $this->toolBox()->i18nLog()->warning('347-no-province', $context);
        }

        if (empty($data['codpais'])) {
            $this->toolBox()->i18nLog()->warning('347-no-country', $context);
        }
    }

    protected function checkData(): void
    {
        // obtenemos el ejercicio
        $exerciseModel = Ejercicios::get($this->codejercicio);

        // obtenemos la empresa del ejercicio
        $companyModel = Empresas::get($exerciseModel->idempresa);

        if (empty($companyModel->administrador)) {
            $this->toolBox()->i18nLog()->warning('company-admin-no-data', ['%company%' => $companyModel->nombre]);
        }

        if (empty($companyModel->telefono1)) {
            $this->toolBox()->i18nLog()->warning('company-phone-no-data', ['%company%' => $companyModel->nombre]);
        }

        if (empty($this->customersData) && empty($this->suppliersData)) {
            $this->toolBox()->i18nLog()->warning('347-no-data');
        }

        foreach ($this->customersData as $data) {
            $this->checkAddress($data, 'customer');
        }

        foreach ($this->suppliersData as $data) {
            $this->checkAddress($data, 'supplier');
        }
    }

    protected function defaultAction(): void
    {
        // buscamos el primer ejercicio abierto, para tenerlo como predeterminado
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
        $this->grouping = $this->request->request->get('grouping', $this->grouping);

        $this->loadCustomersData();
        $this->loadSuppliersData();

        $this->checkData();
    }

    protected function downloadExcelAction(): void
    {
        $this->setTemplate(false);

        $xlsExport = new XLSExport();
        $xlsExport->newDoc($this->toolBox()->i18n()->trans('model-347'), 0, '');
        $i18n = $this->toolBox()->i18n();

        // customers data
        if (false === empty($this->customersData)) {
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
        }

        // suppliers data
        if (false === empty($this->suppliersData)) {
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
        }

        $xlsExport->show($this->response);
    }

    protected function downloadTxtAction(): void
    {
        $this->setTemplate(false);

        // cargamos la información que falte
        if ($this->activetab === 'suppliers') {
            $this->loadCustomersData();
        } else {
            $this->loadSuppliersData();
        }

        // creamos el archivo txt
        $exportFile = FS_FOLDER . '/MyFiles/' . $this->toolBox()->i18n()->trans('model-347') . '.txt';
        if (false === file_put_contents($exportFile, Txt347Export::export($this->codejercicio, $this->customersData, $this->suppliersData))) {
            $this->toolBox()->i18nLog()->error('cant-save-file', ['%fileName%' => $exportFile]);
            return;
        }

        // descargamos el archivo
        $this->response->headers->set('Content-Type', 'text/plain; charset=ISO-8859-1');
        $this->response->headers->set('Content-Disposition', 'attachment; filename="' . basename($exportFile) . '"');
        $this->response->headers->set('Pragma', 'no-cache');
        $this->response->headers->set('Expires', '0');
        $this->response->setContent(file_get_contents($exportFile));

        // eliminamos el archivo
        unlink($exportFile);
    }

    protected function getAccountingInfo(Cuenta $cuenta, string $column): array
    {
        $ejercicio = Ejercicios::get($this->codejercicio);

        if (strtolower(FS_DB_TYPE) == 'postgresql') {
            $sql = "select idsubcuenta, codsubcuenta, to_char(fecha,'FMMM') as mes, sum(" . $column . ") as total from partidas p, asientos a"
                . " where idsubcuenta IN (select idsubcuenta from subcuentas where idcuenta = " . $this->dataBase->var2str($cuenta->idcuenta) . ")"
                . " and p.idasiento = a.idasiento"
                . " and a.operacion is null"
                . " and fecha >= " . $this->dataBase->var2str($ejercicio->fechainicio)
                . " and fecha <= " . $this->dataBase->var2str($ejercicio->fechafin)
                . " group by 1, 2, 3 order by idsubcuenta asc, mes asc;";
        } else {
            $sql = "select idsubcuenta, codsubcuenta, DATE_FORMAT(fecha, '%m') as mes, sum(" . $column . ") as total from partidas p, asientos a"
                . " where idsubcuenta IN (select idsubcuenta from subcuentas where idcuenta = " . $this->dataBase->var2str($cuenta->idcuenta) . ")"
                . " and p.idasiento = a.idasiento"
                . " and a.operacion is null"
                . " and fecha >= " . $this->dataBase->var2str($ejercicio->fechainicio)
                . " and fecha <= " . $this->dataBase->var2str($ejercicio->fechafin)
                . " group by 1, 2, 3 order by idsubcuenta asc, mes asc;";
        }

        return $this->dataBase->select($sql);
    }

    protected function getCodeForTotal(array &$fiscalNumbers, string $code, string $idfiscal): string
    {
        if ($this->grouping === 'cifnif') {
            if (false === isset($fiscalNumbers[$idfiscal])) {
                $fiscalNumbers[$idfiscal] = $code;
            }
            return $fiscalNumbers[$idfiscal];
        }
        return $code;
    }

    protected function getCustomersDataAccounting(): array
    {
        $items = [];
        $fiscalNumbers = [];

        // buscamos las cuentas especiales de clientes de este ejercicio
        $cuentaModel = new Cuenta();
        $where = [
            new DataBaseWhere('codejercicio', $this->codejercicio),
            new DataBaseWhere('codcuentaesp', 'CLIENT')
        ];
        foreach ($cuentaModel->all($where, [], 0, 0) as $cuenta) {
            // buscamos las partidas de las subcuentas de esta cuenta
            foreach ($this->getAccountingInfo($cuenta, 'debe') as $row) {
                // buscamos el cliente de la subcuenta
                $cliente = new Cliente();
                $where = [new DataBaseWhere('codsubcuenta', $row['codsubcuenta'])];
                if (false === $cliente->loadFromCode('', $where)) {
                    // no se ha encontrado el cliente, saltamos
                    continue;
                }

                $codcliente = $this->getCodeForTotal($fiscalNumbers, $cliente->codcliente, $cliente->cifnif);
                if (isset($items[$codcliente])) {
                    $this->groupTotals($items[$codcliente], $row);
                    continue;
                }
                // Es un cliente nuevo. Utilizamos los datos del cliente.
                $items[$cliente->codcliente] = [
                    'cifnif' => '',
                    'cliente' => $cliente->codcliente,
                    'codpostal' => '',
                    'ciudad' => '',
                    'provincia' => '',
                    't1' => 0.0,
                    't2' => 0.0,
                    't3' => 0.0,
                    't4' => 0.0,
                    'total' => 0.0
                ];

                $dir = $cliente->getDefaultAddress();
                $items[$cliente->codcliente]['cifnif'] = $cliente->cifnif;
                $items[$cliente->codcliente]['cliente'] = $cliente->razonsocial;
                $items[$cliente->codcliente]['codpais'] = $dir->codpais;
                $items[$cliente->codcliente]['codpostal'] = $dir->codpostal;
                $items[$cliente->codcliente]['ciudad'] = $dir->ciudad;
                $items[$cliente->codcliente]['provincia'] = $dir->provincia;
                $items[$cliente->codcliente]['tipoidfiscal'] = $cliente->tipoidfiscal;

                $this->groupTotals($items[$cliente->codcliente], $row);
            }
        }

        return $items;
    }

    protected function getCustomersDataInvoices(): array
    {
        $fiscalNumbers = [];
        $items = [];
        $sql = $this->getInvoiceSql('facturascli', 'codcliente');
        foreach ($this->dataBase->select($sql) as $row) {
            $codcliente = $this->getCodeForTotal($fiscalNumbers, $row['codcliente'], $row['cifnif']);
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
                $items[$codcliente]['codpais'] = $dir->codpais;
                $items[$codcliente]['codpostal'] = $dir->codpostal;
                $items[$codcliente]['ciudad'] = $dir->ciudad;
                $items[$codcliente]['provincia'] = $dir->provincia;
                $items[$codcliente]['tipoidfiscal'] = $cliente->tipoidfiscal;
            }

            $this->groupTotals($items[$codcliente], $row);
        }

        return $items;
    }

    /**
     * Devuelve el SQL para obtener los datos para el cálculo de los totales.
     *
     * @param string $tableName
     * @param string $codeField
     * @return string
     */
    protected function getInvoiceSql(string $tableName, string $codeField): string
    {
        $sql = 'SELECT ' . $codeField . ', cifnif, EXTRACT(MONTH FROM fecha) as mes, sum(total) as total'
            . ' FROM ' . $tableName
            . $this->getInvoiceSqlWhere();

        if ($this->excludeIrpf) {
            $sql .= ' AND irpf = 0';
        }

        $sql .= ' GROUP BY 1, 2, 3 ORDER BY 1;';
        return $sql;
    }

    /**
     * Devuelve la cláusula WHERE para filtrar los documentos.
     *
     * @return string
     */
    protected function getInvoiceSqlWhere(): string
    {
        return ' WHERE codejercicio = ' . $this->dataBase->var2str($this->codejercicio)
            . ' AND COALESCE(excluir347, false) = false';
    }

    protected function getSuppliersDataAccounting(): array
    {
        $items = [];
        $fiscalNumbers = [];

        // buscamos las cuentas especiales de proveedores de este ejercicio
        $cuentaModel = new Cuenta();
        $where = [
            new DataBaseWhere('codejercicio', $this->codejercicio),          
            new DataBaseWhere('codcuentaesp', 'PROVEE,ACREED', 'IN')
        
        ];
            foreach ($cuentaModel->all($where, [], 0, 0) as $cuenta) {          
                // consultamos las partidas de cada subcuenta hija
            foreach ($this->getAccountingInfo($cuenta, 'haber') as $row) {
                // buscamos el proveedor de la subcuenta
                $proveedor = new Proveedor();
                $where = [new DataBaseWhere('codsubcuenta', $row['codsubcuenta'])];
                if (false === $proveedor->loadFromCode('', $where)) {
                    // no existe, saltamos
                    continue;
                }

                $codproveedor = $this->getCodeForTotal($fiscalNumbers, $proveedor->codproveedor, $proveedor->cifnif);
                if (isset($items[$codproveedor])) {
                    $this->groupTotals($items[$codproveedor], $row);
                    continue;
                }

                // Es un proveedor nuevo. Utilizamos los datos del proveedor.
                $items[$proveedor->codproveedor] = [
                    'cifnif' => '',
                    'proveedor' => $proveedor->codproveedor,
                    'codpostal' => '',
                    'ciudad' => '',
                    'provincia' => '',
                    't1' => 0.0,
                    't2' => 0.0,
                    't3' => 0.0,
                    't4' => 0.0,
                    'total' => 0.0
                ];

                $dir = $proveedor->getDefaultAddress();
                $items[$proveedor->codproveedor]['cifnif'] = $proveedor->cifnif;
                $items[$proveedor->codproveedor]['proveedor'] = $proveedor->razonsocial;
                $items[$proveedor->codproveedor]['codpais'] = $dir->codpais;
                $items[$proveedor->codproveedor]['codpostal'] = $dir->codpostal;
                $items[$proveedor->codproveedor]['ciudad'] = $dir->ciudad;
                $items[$proveedor->codproveedor]['provincia'] = $dir->provincia;
                $items[$proveedor->codproveedor]['tipoidfiscal'] = $proveedor->tipoidfiscal;

                $this->groupTotals($items[$proveedor->codproveedor], $row);
            }
        }

        return $items;
    }

    protected function getSuppliersDataInvoices(): array
    {
        $fiscalNumbers = [];
        $items = [];
        $sql = $this->getInvoiceSql('facturasprov', 'codproveedor');
        foreach ($this->dataBase->select($sql) as $row) {
            $codproveedor = $this->getCodeForTotal($fiscalNumbers, $row['codproveedor'], $row['cifnif']);
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
                $items[$codproveedor]['codpais'] = $dir->codpais;
                $items[$codproveedor]['codpostal'] = $dir->codpostal;
                $items[$codproveedor]['ciudad'] = $dir->ciudad;
                $items[$codproveedor]['provincia'] = $dir->provincia;
                $items[$codproveedor]['tipoidfiscal'] = $proveedor->tipoidfiscal;
            }

            $this->groupTotals($items[$codproveedor], $row);
        }

        return $items;
    }

    protected function groupTotals(array &$item, array $row): void
    {
        if (in_array($row['mes'], ['1', '2', '3', '01', '02', '03'])) {
            $item['t1'] += (float)$row['total'];
        } elseif (in_array($row['mes'], ['4', '5', '6', '04', '05', '06'])) {
            $item['t2'] += (float)$row['total'];
        } elseif (in_array($row['mes'], ['7', '8', '9', '07', '08', '09'])) {
            $item['t3'] += (float)$row['total'];
        } else {
            $item['t4'] += (float)$row['total'];
        }

        $item['total'] += (float)$row['total'];
    }

    protected function loadCustomersData()
    {
        $this->customersData = $this->examine === 'invoices' ?
            $this->getCustomersDataInvoices() :
            $this->getCustomersDataAccounting();

        // exclude if total lower than amount
        foreach ($this->customersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->customersData[$key]);
            }
        }

        // totals
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
        $this->suppliersData = $this->examine === 'invoices' ?
            $this->getSuppliersDataInvoices() :
            $this->getSuppliersDataAccounting();

        // exclude if total lower than amount
        foreach ($this->suppliersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->suppliersData[$key]);
            }
        }

        // totals
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
