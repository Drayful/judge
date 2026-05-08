<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\Performance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InquiryController extends Controller
{
    public function store(Performance $performance, Request $request): RedirectResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'panel' => ['nullable', 'string', Rule::in(['d', 'a', 'e', 'penalty'])],
            'subpanel' => ['nullable', 'string', Rule::in(['db', 'da'])],
            'penalty_type' => ['nullable', 'string', 'max:40'],
        ]);

        Inquiry::query()->create([
            'performance_id' => $performance->id,
            'created_by' => $request->user()?->id,
            'reason' => $request->input('reason'),
            'panel' => $request->input('panel'),
            'subpanel' => $request->input('subpanel'),
            'penalty_type' => $request->input('penalty_type'),
            'status' => 'submitted',
        ]);

        // Lock flow until decided/published.
        $performance->status = 'inquiry';
        $performance->save();

        return back()->with('status', 'Inquiry создан. Статус: submitted.');
    }

    public function markUnderReview(Inquiry $inquiry, Request $request): RedirectResponse
    {
        $inquiry->status = 'under_review';
        $inquiry->decided_by = $request->user()?->id;
        $inquiry->decided_at = now();
        $inquiry->save();

        $inquiry->performance?->update(['status' => 'under_review']);

        return back()->with('status', 'Inquiry: under_review.');
    }

    public function decide(Inquiry $inquiry, Request $request): RedirectResponse
    {
        $request->validate([
            'decision' => ['required', 'string', Rule::in(['accepted', 'rejected', 'partially_accepted'])],
            'decision_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $inquiry->status = 'decided';
        $inquiry->decision = $request->string('decision')->toString();
        $inquiry->decision_notes = $request->input('decision_notes') ?: null;
        $inquiry->decided_by = $request->user()?->id;
        $inquiry->decided_at = now();
        $inquiry->save();

        // Return performance to scoring flow. Publishing is still controlled by approve/publish buttons.
        if ($inquiry->performance) {
            $inquiry->performance->status = $inquiry->performance->published_at ? 'published' : 'done';
            $inquiry->performance->save();
        }

        return back()->with('status', 'Inquiry: decided.');
    }
}
