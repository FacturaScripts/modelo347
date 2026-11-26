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

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Dinamic\Lib\Modelo347Api;

/**
 * Endpoint de solo lectura de la API con los datos del Modelo 347: GET /api/3/modelo347.
 *
 * Devuelve los clientes y proveedores a declarar del ejercicio, con los mismos importes que la
 * pantalla del plugin. No genera ficheros, no envía emails ni modifica ningún registro.
 *
 * Parámetros (query string): codejercicio, idempresa, anio, periodo (solo ANUAL), importe,
 * origen (facturas|contabilidad), agrupar (tercero|cifnif) y excluir_irpf. Ver Lib/Modelo347Api.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class ApiModelo347 extends ApiController
{
    /** Parámetros de la query string que acepta el endpoint. */
    private const PARAMS = ['codejercicio', 'idempresa', 'anio', 'periodo', 'detalle', 'importe', 'origen', 'agrupar', 'excluir_irpf'];

    /**
     * Valida el método, lee los parámetros y devuelve los datos del modelo.
     */
    protected function runResource(): void
    {
        if ($this->request->method() !== 'GET') {
            $this->response
                ->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED)
                ->json(['status' => 'error', 'message' => 'method-not-allowed']);
            return;
        }

        $params = [];
        foreach (self::PARAMS as $key) {
            $params[$key] = $this->request->query($key);
        }

        $result = Modelo347Api::build($params);
        $this->response
            ->setHttpCode($result['code'])
            ->json($result['body']);
    }
}
