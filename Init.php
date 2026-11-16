<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2017-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\Modelo347;

use FacturaScripts\Core\Lib\AjaxForms\PurchasesHeaderHTML;
use FacturaScripts\Core\Lib\AjaxForms\SalesHeaderHTML;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;

/**
 * Description of Init
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 */
final class Init extends InitClass
{
    /**
     * Carga las extensiones, mods y workers del plugin.
     */
    public function init(): void
    {
        // extensiones
        $this->loadExtension(new Extension\Lib\BusinessDocumentGenerator());
        $this->loadExtension(new Extension\Model\Cliente());
        $this->loadExtension(new Extension\Model\FacturaCliente());
        $this->loadExtension(new Extension\Model\FacturaProveedor());
        $this->loadExtension(new Extension\Model\Proveedor());

        // mods
        PurchasesHeaderHTML::addMod(new Mod\PurchasesHeaderHTMLMod());
        SalesHeaderHTML::addMod(new Mod\SalesHeaderHTMLMod());

        // workers
        WorkQueue::addWorker('Modelo347CustomersEmailWorker', 'Modelo347.SendCustomersEmails');
        WorkQueue::addWorker('Modelo347SuppliersEmailWorker', 'Modelo347.SendSuppliersEmails');
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        new FacturaCliente();
        new FacturaProveedor();
    }
}
