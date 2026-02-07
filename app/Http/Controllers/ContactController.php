<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Display the contact form.
     */
    public function index(): Response
    {
        return Inertia::render('contact');
    }

    /**
     * Handle the contact form submission.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'service_type' => ['nullable', 'string', Rule::in([
                'supported_living',
                'residential_care',
                'domiciliary',
                'respite',
                'ld_services',
                'mental_health',
                'other',
            ])],
            'residents_count' => ['nullable', 'string', Rule::in([
                '1-10',
                '11-25',
                '26-50',
                '51-100',
                '100+',
            ])],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        // Prepare email data
        $emailData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] ?? 'Not provided',
            'phone' => $validated['phone'] ?? 'Not provided',
            'service_type' => $this->getServiceTypeLabel($validated['service_type'] ?? null),
            'residents_count' => $validated['residents_count'] ?? 'Not provided',
            'message' => $validated['message'],
        ];

        // Send email to admin
        try {
            Mail::send('emails.contact', $emailData, function ($message) use ($validated) {
                $message->to(config('mail.contact_email', 'hello@oblivionfindings.co.uk'))
                    ->subject('New Contact Form Submission: ' . $validated['name'])
                    ->replyTo($validated['email'], $validated['name']);
            });

            // Send confirmation to user
            Mail::send('emails.contact-confirmation', $emailData, function ($message) use ($validated) {
                $message->to($validated['email'], $validated['name'])
                    ->subject('Thank you for contacting Oblivion Findings');
            });
        } catch (\Exception $e) {
            // Log the error but don't show it to the user
            logger()->error('Failed to send contact email: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Your message has been sent. We\'ll be in touch soon!');
    }

    /**
     * Get human-readable service type label.
     */
    private function getServiceTypeLabel(?string $type): string
    {
        $labels = [
            'supported_living' => 'Supported Living',
            'residential_care' => 'Residential Care Home',
            'domiciliary' => 'Domiciliary Care',
            'respite' => 'Respite/Short Breaks',
            'ld_services' => 'Learning Disability Services',
            'mental_health' => 'Mental Health Services',
            'other' => 'Other',
        ];

        return $labels[$type] ?? 'Not provided';
    }
}
