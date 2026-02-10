@extends('layouts.app')

@section('content')
<div class="container">
    <div class="card">
        <div class="card-body">
            @php
                $u = auth()->user();

                $displayName = $u
                    ? ($u->name
                        ?? $u->display_name ?? $u->displayName
                        ?? $u->full_name ?? $u->fullname
                        ?? $u->username ?? $u->user_name
                        ?? $u->nick_name ?? $u->nickname ?? $u->nick
                        ?? $u->first_name ?? $u->firstname ?? $u->firstName ?? $u->fname ?? $u->f_name
                        ?? $u->email
                        ?? 'Devs')
                    : 'Devs';

                $restoreCmdPrompt = 'php artisan crud:install';
                $restoreCmdForce  = 'php artisan crud:install --force';
            @endphp

            <h1 class="card-title text-center text-lg-start">
                Hey <span class="{{ auth()->check() ? 'text-primary' : '' }}">{{ $displayName }}</span> — Welcome Aboard 👋
            </h1>
            <hr/>

            <p class="card-text fs-5">
                Fresh <strong>Laravel v{{ str_replace('Laravel Framework ', '', app()->version()) }}</strong>
                install with <strong>Bootstrap 5</strong> + <strong>Font Awesome 7.0.1</strong> 🚀
            </p>

            {{-- Package name + description --}}
            <div class="mt-3">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="fw-semibold text-decoration-underline">Package name:</span>
                    <span class="badge text-bg-light border fs-6 fw-semibold">kareemtarek/crud-pack</span>
                </div>

                <p class="mb-2 text-muted fs-6">
                    A full-stack CRUD booster for Laravel — generates clean back-end endpoints and ready-to-use UI scaffolding,
                    helping you ship admin panels faster with consistent routes, validation, and resource structure.
                </p>

                {{-- Important note (responsive) --}}
                <div class="crud-note mt-3 p-3 rounded border crud-note-border crud-note-bg">
                    {{-- column on mobile, row on sm+ --}}
                    <div class="d-flex flex-column flex-sm-row gap-3 align-items-stretch">
                        {{-- left side: keep the same look, but responsive --}}
                        <div class="text-center crud-note-left d-flex flex-column">
                            <span class="badge text-bg-info d-inline-flex align-items-center px-3 py-2 fs-6 justify-content-center">
                                <i class="fa-solid fa-circle-exclamation me-2"></i> Heads up
                            </span>

                            <div class="crud-triangle-wrap" aria-hidden="true" title="Warning">
                                <i class="fa-solid fa-triangle-exclamation crud-triangle"></i>
                            </div>
                        </div>

                        {{-- right side content --}}
                        <div class="fs-6">
                            <strong>Auth scaffolding can overwrite views.</strong>
                            If you install an auth package (Breeze or Jetstream with Fortify, UI, etc.), it may replace the package’s
                            pre-scaffolded Blade templates.

                            <div class="mt-2">
                                <div class="text-muted small mb-2">
                                    Prefer a safe run with a CLI prompt (so you can choose not to overwrite)?
                                </div>

                                <div class="d-flex flex-column flex-md-row flex-wrap align-items-start align-items-md-center gap-2 mb-2">
                                    <code id="crudRestoreCmdPrompt" class="px-2 py-1 rounded bg-dark text-light">{{ $restoreCmdPrompt }}</code>

                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center"
                                            data-copy-target="crudRestoreCmdPrompt"
                                            title="Copy command">
                                        <i class="fa-regular fa-copy me-1"></i> Copy
                                    </button>

                                    <span class="small text-success d-none" id="copyCrudCmdPromptMsg">
                                        <i class="fa-solid fa-check me-1"></i> Copied!
                                    </span>
                                </div>

                                <div class="text-muted small mb-2">
                                    If the views were overwritten and you want to restore the <strong>kareemtarek/crud-pack</strong> scaffolding:
                                </div>

                                <div class="d-flex flex-column flex-md-row flex-wrap align-items-start align-items-md-center gap-2">
                                    <code id="crudRestoreCmdForce" class="px-2 py-1 rounded bg-dark text-light">{{ $restoreCmdForce }}</code>

                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center"
                                            data-copy-target="crudRestoreCmdForce"
                                            title="Copy command">
                                        <i class="fa-regular fa-copy me-1"></i> Copy
                                    </button>

                                    <span class="small text-success d-none" id="copyCrudCmdForceMsg">
                                        <i class="fa-solid fa-check me-1"></i> Copied!
                                    </span>
                                </div>

                                <hr/>
                                
                                {{-- Auth placeholder note --}}
                                <div class="alert alert-info mt-2 mb-0 py-2 px-3 small"> {{-- class="mt-2 small text-muted" --}}
                                    <i class="fa-solid fa-circle-info me-1"></i>
                                    If you have an authentication package installed, there’s a placeholder auth section in
                                    <code class="px-1">resources/views/layouts/navigation.blade.php</code>
                                    (for <strong>authenticated users</strong> and <strong>guests</strong>).
                                    It’s currently commented out to support projects without authentication.
                                    If you later add auth (Breeze or Jetstream with Fortify, UI, custom auth, etc.), simply uncomment it.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Package links --}}
            <div class="mt-4">
                <div class="fs-6 text-muted mb-2">Package links:</div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="https://github.com/Kareem-Tarek/crud-pack"
                       class="btn btn-dark btn-md"
                       target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-github me-1"></i> GitHub Repo
                    </a>

                    <a href="https://packagist.org/packages/kareemtarek/crud-pack"
                       class="btn btn-packagist btn-md"
                       target="_blank" rel="noopener noreferrer">
                        <i class="fa-solid fa-box me-1"></i> Packagist
                    </a>
                </div>
            </div>

            {{-- Author --}}
            <div class="mt-4 fs-5 text-muted">
                Made with <span class="text-danger">❤️</span> by <strong>Kareem Tarek</strong> —
                <a href="https://kareemtarek.infinityfree.me/" target="_blank" rel="noopener noreferrer">
                    Author's Portfolio
                </a><br>
                Built with love and support for fellow developers.
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    /* Packagist-like button */
    .btn-packagist{
        --pk: #f28d1a;
        --pk-focus: rgba(242,141,26,.35);

        background-color: transparent;
        border: 1px solid var(--pk);
        color: var(--pk);
        font-weight: 600;
        transition: color .15s ease-in-out, background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out, transform .05s ease-in-out;
    }
    .btn-packagist:hover{
        background-color: var(--pk);
        border-color: var(--pk);
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-packagist:active{ transform: translateY(0); }
    .btn-packagist:focus,
    .btn-packagist:focus-visible{
        box-shadow: 0 0 0 .25rem var(--pk-focus);
    }

    /* Subtle custom note colors */
    .crud-note-bg{
        background: linear-gradient(90deg, rgba(13,202,240,.14), rgba(13,110,253,.06));
    }
    .crud-note-border{
        border-color: rgba(13,202,240,.45) !important;
    }

    /* Left block: fixed width on sm+, full width on xs */
    .crud-note-left{
        width: 100%;
    }
    @media (min-width: 576px){
        .crud-note-left{
            width: 160px;
        }
    }

    /* Triangle area: centers triangle under badge. On mobile it becomes a neat block below badge. */
    .crud-triangle-wrap{
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: .5rem;
        min-height: 65px;
    }

    /* Triangle: a bit bigger, smooth flash */
    .crud-triangle{
        font-size: 3.5rem;
        line-height: 1;
        color: #f59f00;
        animation: trianglePulse 1.6s ease-in-out infinite;
    }
    @keyframes trianglePulse{
        0%, 100%{
            opacity: .55;
            transform: scale(.98);
            filter: drop-shadow(0 0 0 rgba(245,159,0,0));
        }
        50%{
            opacity: 1;
            transform: scale(1.07);
            filter: drop-shadow(0 0 .45rem rgba(245,159,0,.35));
        }
    }

    /* Attention glow for the note */
    .crud-note{
        animation: crudGlow 2.2s ease-in-out infinite;
    }
    @keyframes crudGlow{
        0%, 100%{
            box-shadow: 0 0 0 0 rgba(13,202,240,.0);
            filter: brightness(1);
            transform: translateY(0);
        }
        50%{
            box-shadow: 0 0 0 .40rem rgba(13,202,240,.16);
            filter: brightness(1.06);
            transform: translateY(-1px);
        }
    }

    /* Respect reduced motion */
    @media (prefers-reduced-motion: reduce){
        .crud-note{ animation: none; }
        .crud-triangle{ animation: none; }
        .btn-packagist{ transition: none; }
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('button[data-copy-target]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const targetId = btn.getAttribute('data-copy-target');
            const el = document.getElementById(targetId);
            if (!el) return;

            const text = el.textContent.trim();

            const msgId = targetId === 'crudRestoreCmdPrompt'
                ? 'copyCrudCmdPromptMsg'
                : 'copyCrudCmdForceMsg';

            const msg = document.getElementById(msgId);

            try {
                await navigator.clipboard.writeText(text);

                msg?.classList.remove('d-none');
                btn.classList.add('disabled');

                setTimeout(() => {
                    msg?.classList.add('d-none');
                    btn.classList.remove('disabled');
                }, 1500);
            } catch (e) {
                const range = document.createRange();
                range.selectNodeContents(el);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);

                msg?.classList.remove('d-none');
                setTimeout(() => msg?.classList.add('d-none'), 1500);
            }
        });
    });
});
</script>
@endpush
