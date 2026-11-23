<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shows · {{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>Shows</h1>

        @if ($shows->isEmpty())
            <p>No shows yet. Run php artisan lwtv:sync.</p>
        @else
            <p>{{ number_format($shows->count()) }} shows from LezWatch.TV.</p>
            <ul>
                @foreach ($shows as $show)
                    <li>
                        <a href="{{ $show->url }}">{{ $show->title }}</a>
                        @if ($show->on_air)
                            <span>(On air)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </main>
</body>
</html>
