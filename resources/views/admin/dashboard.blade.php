@extends('admin.layout')

@section('title', 'Sync log')

@section('content')
    <h1>Sync log</h1>

    @if (session('admin.status'))
        <p class="alert alert-ok" role="status">{{ session('admin.status') }}</p>
    @endif
    @if (session('admin.error'))
        <p class="alert alert-bad" role="alert">{{ session('admin.error') }}</p>
    @endif

    <div class="summary">
        <div>
            <strong>Last success</strong><br>
            @if ($lastSuccess)
                <time datetime="{{ $lastSuccess->started_at->toIso8601String() }}">{{ $lastSuccess->started_at->diffForHumans() }}</time>,
                {{ number_format($lastSuccess->total) }} shows
            @else
                <span class="muted">None yet</span>
            @endif
        </div>
        <div>
            <strong>Last failure</strong><br>
            @if ($lastFailure)
                <time datetime="{{ $lastFailure->started_at->toIso8601String() }}">{{ $lastFailure->started_at->diffForHumans() }}</time>
                @if ($lastFailure->isStalled())
                    (stalled)
                @endif
            @else
                <span class="muted">None</span>
            @endif
        </div>
    </div>

    <div class="actions">
        <form method="post" action="{{ route('admin.sync') }}">
            @csrf
            <button type="submit" class="btn">Sync now</button>
        </form>
        @if ($failedOnly)
            <a href="{{ route('admin.dashboard') }}">Show all runs</a>
        @else
            <a href="{{ route('admin.dashboard', ['failed' => 1]) }}">Show failed and stalled only</a>
        @endif
    </div>

    @if ($runs->isEmpty())
        <p class="muted">{{ $failedOnly ? 'No failed or stalled syncs.' : 'No syncs recorded yet.' }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Started (UTC)</th>
                        <th scope="col">Trigger</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Shows</th>
                        <th scope="col" class="num">Added</th>
                        <th scope="col" class="num">Updated</th>
                        <th scope="col" class="num">Removed</th>
                        <th scope="col">Error</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($runs as $run)
                        @php($status = $run->isStalled() ? 'stalled' : $run->status)
                        <tr>
                            <td><time datetime="{{ $run->started_at->toIso8601String() }}">{{ $run->started_at->format('Y-m-d H:i') }}</time></td>
                            <td>{{ ucfirst($run->trigger) }}</td>
                            <td><span class="badge badge-{{ $status }}">{{ ucfirst($status) }}</span></td>
                            <td class="num">{{ $run->total === null ? '—' : number_format($run->total) }}</td>
                            <td class="num">{{ $run->added === null ? '—' : number_format($run->added) }}</td>
                            <td class="num">{{ $run->updated === null ? '—' : number_format($run->updated) }}</td>
                            <td class="num">{{ $run->removed === null ? '—' : number_format($run->removed) }}</td>
                            <td class="error">{{ $run->error }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
