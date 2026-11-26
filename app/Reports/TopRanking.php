<?php

namespace App\Reports;

use Illuminate\Http\Request;

/**
 * Puesto y corte de los rankings del centro de reportes (docs/reportes-rankings.md, decisiones 5 y 6).
 */
final class TopRanking
{
    /**
     * Puesto de cada fila ya ordenada: el mismo si la cifra principal empata, y el siguiente salta (1, 2, 2, 4).
     *
     * @param  list<int|float>  $values
     * @return list<int>
     */
    public static function places(array $values): array
    {
        $places = [];
        foreach ($values as $i => $value) {
            $places[] = $i > 0 && $value == $values[$i - 1] ? $places[$i - 1] : $i + 1;
        }

        return $places;
    }

    /**
     * Los primeros `top` (1–100) de la lista; sin `top`, la lista completa.
     *
     * @template T
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function cut(array $rows, Request $request): array
    {
        return $request->filled('top') ? array_slice($rows, 0, $request->integer('top')) : $rows;
    }
}
