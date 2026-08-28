<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Quote PoC — notes to draft</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        <style>
            :root {
                color-scheme: light dark;
                --bg: #f4f1ea;
                --ink: #1b1b18;
                --muted: #5c5a52;
                --card: #fffcf6;
                --line: #d9d4c8;
                --accent: #1b1b18;
                --accent-ink: #fffcf6;
                --ok: #1f6b3a;
                --ok-bg: #e7f5eb;
                --err: #b42318;
                --err-bg: #fdecea;
                --code: #ece8dc;
            }
            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #12110f;
                    --ink: #ededec;
                    --muted: #a1a09a;
                    --card: #1c1b18;
                    --line: #3e3e3a;
                    --accent: #eeeeec;
                    --accent-ink: #1c1c1a;
                    --ok: #86efac;
                    --ok-bg: #16341f;
                    --err: #fca5a5;
                    --err-bg: #3b1614;
                    --code: #2a2926;
                }
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
                background: var(--bg);
                color: var(--ink);
            }
            main {
                max-width: 78rem;
                margin: 0 auto;
                padding: 2.5rem 1.25rem 4rem;
            }
            .eyebrow {
                font-size: 0.75rem;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--muted);
                margin: 0 0 0.5rem;
            }
            h1 { font-size: 1.75rem; line-height: 1.2; margin: 0 0 0.75rem; }
            p, li { line-height: 1.55; }
            .lede { color: var(--muted); margin: 0 0 1.75rem; }
            ol.flow { margin: 0; padding: 0; list-style: none; counter-reset: step; }
            ol.flow li {
                counter-increment: step;
                position: relative;
                padding: 0.65rem 0 0.65rem 2.5rem;
                border-bottom: 1px solid var(--line);
                color: var(--muted);
            }
            ol.flow li::before {
                content: counter(step);
                position: absolute;
                left: 0;
                top: 0.65rem;
                width: 1.5rem;
                height: 1.5rem;
                border-radius: 999px;
                border: 1px solid var(--line);
                display: grid;
                place-items: center;
                font-size: 0.75rem;
                color: var(--ink);
            }
            ol.flow strong { color: var(--ink); }
            .panel {
                background: var(--card);
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 1.25rem;
            }
            .success { background: var(--ok-bg); border-color: color-mix(in srgb, var(--ok) 35%, var(--line)); }
            .success h2, .panel h2 { margin: 0 0 0.5rem; font-size: 1.05rem; }
            .success h2 { color: var(--ok); }
            .errors { background: var(--err-bg); border-color: color-mix(in srgb, var(--err) 35%, var(--line)); color: var(--err); margin-bottom: 1rem; }
            label { display: block; font-weight: 600; margin-bottom: 0.5rem; }
            input[type="file"] { width: 100%; margin-bottom: 1rem; }
            button, .copy, .approve {
                appearance: none;
                border: 1px solid var(--accent);
                background: var(--accent);
                color: var(--accent-ink);
                border-radius: 6px;
                padding: 0.55rem 1rem;
                font: inherit;
                cursor: pointer;
            }
            .approve {
                display: inline-block;
                margin-top: 0.75rem;
                font-weight: 600;
                min-height: 2.5rem;
            }
            .copy {
                background: transparent;
                color: var(--ink);
                margin-left: 0.5rem;
                padding: 0.25rem 0.6rem;
                font-size: 0.8rem;
            }
            code, pre {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.85rem;
                background: var(--code);
                padding: 0.15rem 0.35rem;
                border-radius: 4px;
            }
            pre {
                display: block;
                padding: 0.85rem;
                overflow-x: auto;
                white-space: pre-wrap;
                margin: 0.75rem 0 0;
            }
            .markdown-guide { margin-bottom: 0; }
            .markdown-guide h2 { margin: 0 0 0.75rem; font-size: 1.05rem; }
            .markdown-guide h3 { margin: 1.25rem 0 0.5rem; font-size: 0.95rem; }
            .markdown-guide p, .markdown-guide li { color: var(--muted); }
            .markdown-guide ol, .markdown-guide ul { margin: 0.5rem 0 0.75rem; padding-left: 1.25rem; }
            .markdown-guide table { display: block; overflow-x: auto; }
            .markdown-guide a { color: var(--ink); }
            .landing-split {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                gap: 1.25rem;
                align-items: start;
            }
            .landing-column {
                display: flex;
                flex-direction: column;
                gap: 1.25rem;
                min-width: 0;
            }
            .column-title { font-size: 1.05rem; margin: 0 0 0.25rem; }
            .feedback { margin-bottom: 1.25rem; }
            @media (max-width: 56rem) {
                .landing-split { grid-template-columns: minmax(0, 1fr); }
            }
            table { width: 100%; border-collapse: collapse; margin: 0.75rem 0; font-size: 0.9rem; }
            th, td { text-align: left; padding: 0.4rem 0.35rem; border-bottom: 1px solid var(--line); }
            th { color: var(--muted); font-weight: 600; }
            .row { margin: 0.5rem 0; }
            .hint { color: var(--muted); font-size: 0.9rem; margin: 0.75rem 0 0; }
            .copy { margin-left: 0; margin-top: 0.5rem; }
        </style>
    </head>
    <body>
        <main>
            <p class="eyebrow">Laravel MCP quote proof of concept</p>
            <h1>Turn a visit note into a draft quote</h1>
            <p class="lede">
                Upload the seller’s UTF-8 <code>.txt</code> or <code>.md</code> file. Laravel stores it privately, builds a briefing,
                and — when customer and products resolve uniquely — creates a <strong>draft quote</strong> with catalog prices.
                Note prices are ignored. The quote is not approved.
            </p>

            @if (! empty($quoteReport))
                @php($report = $quoteReport)
                <section class="panel success feedback" id="seller-notes-quote">
                    <h2>
                        @if ($report['status'] === 'approved')
                            Quote approved.
                        @else
                            Draft quote created. It is not approved.
                        @endif
                    </h2>
                    <p class="row">Quote <code>{{ $report['quote_number'] }}</code> · status <code>{{ $report['status'] }}</code></p>
                    <p class="row">
                        {{ $report['customer']['customer_name'] }}
                        (<code>{{ $report['customer']['customer_code'] }}</code>)
                    </p>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Unit price</th>
                                <th>Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['items'] as $item)
                                <tr>
                                    <td><code>{{ $item['product_code'] }}</code> {{ $item['product_name'] }}</td>
                                    <td>{{ $item['quantity'] }}</td>
                                    <td>{{ $item['unit_price'] }}</td>
                                    <td>{{ $item['line_total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="row"><strong>Total {{ $report['currency'] }} {{ $report['total'] }}</strong></p>
                    <p class="hint">{{ $report['approval_summary'] }} Money comes from the catalog snapshot, not from the notes file.</p>
                    @if (($report['status'] ?? '') !== 'approved')
                        <form method="POST" action="{{ route('seller-notes.quotes.approve', $report['quote_id']) }}" class="row" id="approve-quote-form">
                            @csrf
                            <button type="submit" class="approve" id="approve-quote">Approve quote</button>
                        </form>
                    @endif
                    @if (session('storage_path'))
                        <p class="row">Stored notes: <code id="seller-notes-storage-path">{{ session('storage_path') }}</code></p>
                    @endif
                </section>
            @elseif (session('storage_path'))
                <section class="panel feedback" id="seller-notes-stored">
                    <h2>Notes stored. No quote was created.</h2>
                    <p class="row">Original filename: <code>{{ session('filename') }}</code></p>
                    <p class="row">storage_path: <code id="seller-notes-storage-path">{{ session('storage_path') }}</code></p>
                    <p class="hint">Fix the lookup error below, or adjust the notes so one customer and each product match the catalog uniquely.</p>
                </section>
            @endif

            @if ($errors->any())
                <div class="panel errors feedback" role="alert">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            <div class="landing-split" id="landing-split">
                <div class="landing-column" id="notes-flow">
                    <section class="panel">
                        <h2 class="column-title">From a notes file</h2>
                        <ol class="flow">
                            <li><strong>Capture.</strong> Copy or download the template below, then save the visit as a UTF-8 notes file (one customer, <code>Nx</code> products, quantities).</li>
                            <li><strong>Upload.</strong> Laravel stores the file on the private disk.</li>
                            <li><strong>Resolve and persist.</strong> Same engine as MCP <code>generate_quote_report</code>: lookup catalog codes, snapshot prices, save a draft.</li>
                            <li><strong>Review here.</strong> This page shows <code>QUO-*</code>, line money, and total. Saving a PDF stays a later step.</li>
                        </ol>
                    </section>

                    <section class="panel" id="seller-notes-template">
                        <h2>Notes file template</h2>
                        <p class="hint">
                            Save this as a UTF-8 <code>.txt</code> file (or download it), then upload below.
                            Use one catalog customer, lines like <code>2x Product Name</code>, and quantities.
                            Amounts such as <code>R$ 199</code> are ignored. Do not put real emails, phones, or tax documents in files you share.
                        </p>
                        <pre id="seller-notes-template-body">{{ $notesTemplate }}</pre>
                        <p class="row">
                            <button type="button" class="copy" data-copy-from="seller-notes-template-body">Copy template</button>
                            <a class="copy" href="{{ route('seller-notes.template') }}" download="seller-notes-template.txt">Download .txt</a>
                        </p>
                    </section>

                    <section class="panel" id="seller-notes">
                        <form method="POST" action="{{ route('seller-notes.store') }}" enctype="multipart/form-data">
                            @csrf
                            <label for="seller-notes-file">Seller notes file</label>
                            <input
                                id="seller-notes-file"
                                type="file"
                                name="notes"
                                accept=".txt,.md,text/plain,text/markdown"
                                required
                            >
                            <button type="submit">Create draft quote from notes</button>
                            <p class="hint">Max 64 KiB. UTF-8 <code>.txt</code> or <code>.md</code> only. Unique catalog matches required. Emails, phones, and note prices are not shown as quote money.</p>
                        </form>
                    </section>
                </div>

                <section class="panel markdown-guide landing-column" id="mcp-connect">
                    {!! $mcpGuideHtml !!}
                </section>
            </div>
        </main>
        <script>
            document.querySelectorAll('[data-copy]').forEach((button) => {
                button.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(button.getAttribute('data-copy') ?? '');
                        button.textContent = 'Copied';
                    } catch {
                        button.textContent = 'Copy failed';
                    }
                });
            });
            document.querySelectorAll('[data-copy-from]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const source = document.getElementById(button.getAttribute('data-copy-from') ?? '');
                    try {
                        await navigator.clipboard.writeText(source?.textContent?.trim() ?? '');
                        button.textContent = 'Copied';
                    } catch {
                        button.textContent = 'Copy failed';
                    }
                });
            });
        </script>
    </body>
</html>
