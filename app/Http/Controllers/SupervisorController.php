<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use Illuminate\Http\RedirectResponse;

class SupervisorController extends Controller
{
    public function approve(Performance $performance): RedirectResponse
    {
        $performance->approved_at = now();
        $performance->save();

        return back()->with('status', 'Выступление утверждено.');
    }

    public function publish(Performance $performance): RedirectResponse
    {
        if (!$performance->approved_at) {
            return back()->with('status', 'Сначала нужно утвердить.');
        }

        $performance->published_at = now();
        $performance->status = 'published';
        $performance->save();

        return back()->with('status', 'Опубликовано на табло.');
    }
}
