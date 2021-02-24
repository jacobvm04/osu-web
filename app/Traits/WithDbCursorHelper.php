<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Traits;

use App\Libraries\DbCursorHelper;

trait WithDbCursorHelper
{
    public static function makeDbCursorHelper(?string $sort = null)
    {
        return new DbCursorHelper(static::SORTS, static::DEFAULT_SORT, $sort);
    }

    public function scopeCursorSort($query, $sortOrCursorHelper, $cursorArrayOrStatic)
    {
        $cursorHelper = $sortOrCursorHelper instanceof DbCursorHelper
            ? $sortOrCursorHelper
            : static::makeDbCursorHelper(get_string($sortOrCursorHelper));

        $query->cursorSortExecOrder($cursorHelper->getSort());

        $cursor = $cursorArrayOrStatic instanceof static
            ? $cursorHelper->itemToCursor($cursorArrayOrStatic)
            : $cursorArrayOrStatic;

        $query->cursorSortExecWhere($cursorHelper->prepare($cursor));

        return $query;
    }

    public function scopeCursorSortExecOrder($query, array $sort)
    {
        foreach ($sort as $sortItem) {
            $query->orderBy($sortItem['column'], $sortItem['order']);
        }

        return $query;
    }

    public function scopeCursorSortExecWhere($query, ?array $preparedCursors)
    {
        if (empty($preparedCursors)) {
            return;
        }

        $currentCursor = array_shift($preparedCursors);

        $dir = strtoupper($currentCursor['order']) === 'DESC' ? '<' : '>';

        if (count($preparedCursors) === 0) {
            $query->where($currentCursor['column'], $dir, $currentCursor['value']);
        } else {
            $query->where($currentCursor['column'], "{$dir}=", $currentCursor['value'])
                ->where(function ($q) use ($currentCursor, $dir, $preparedCursors) {
                    return $q->where($currentCursor['column'], $dir, $currentCursor['value'])
                        ->orWhere(function ($qq) use ($preparedCursors) {
                            return $qq->cursorSortExecWhere($preparedCursors);
                        });
                });
        }

        return $query;
    }
}
