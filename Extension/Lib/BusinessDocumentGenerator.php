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

namespace FacturaScripts\Plugins\Modelo347\Extension\Lib;

use Closure;

/**
 * Extensión del generador de documentos de negocio.
 *   - generateBefore: hereda excluir347 de la ficha del cliente o proveedor
 *     cuando la factura se genera desde un documento que no tiene esa columna
 *     (pedidos y albaranes), ya que en ese caso no se llama a setSubject().
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class BusinessDocumentGenerator
{
    /**
     * Copia excluir347 del cliente o proveedor al nuevo documento antes de guardarlo.
     *
     * No hace nada si el nuevo documento no tiene la columna, si el documento
     * de origen ya la tiene (su valor ya se ha copiado) o si el valor se ha
     * indicado expresamente en las propiedades de la generación.
     *
     * @return Closure
     */
    public function generateBefore(): Closure
    {
        return function ($prototype, $lines, $quantity, $properties, $newDoc) {
            if (false === $newDoc->hasColumn('excluir347')
                || $prototype->hasColumn('excluir347')
                || array_key_exists('excluir347', $properties)) {
                return;
            }

            $subject = $newDoc->getSubject();
            if ($subject->exists() && $subject->hasColumn('excluir347')) {
                $newDoc->excluir347 = (bool)$subject->excluir347;
            }
        };
    }
}
