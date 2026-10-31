<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-white p-8 font-sans text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        {{-- Placeholder until the Identity module provides login (Sprint 1.1). --}}
        <main class="mx-auto max-w-xl">
            <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
            <p class="mt-2">The application is running. Sign-in arrives with the Identity module.</p>
        </main>
    </body>
</html>
