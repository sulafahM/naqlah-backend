<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function numericRouteId(string $value): int
    {
        $id = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);

        abort_if($id === false, 404, 'Resource not found.');

        return $id;
    }
}
