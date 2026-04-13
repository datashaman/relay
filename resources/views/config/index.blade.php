<x-layouts.app title="Autonomy Engine">
@php
    $hierarchy = [
        ['num' => '01', 'key' => 'Escalation', 'desc' => 'Override rules triggered by sensitive files, labels, or patterns. Always tighten — cannot be bypassed.'],
        ['num' => '02', 'key' => 'Issue', 'desc' => 'Per-issue overrides. Can only loosen from the stage default.'],
        ['num' => '03', 'key' => 'Stage', 'desc' => 'Pipeline-specific constraints (Preflight, Implement, Verify, Release). Can only tighten from global.'],
        ['num' => '04', 'key' => 'Global', 'desc' => 'Default operational posture for all agent interactions.'],
    ];
@endphp

<div class="space-y-6 mb-6">
    <div>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">System Configuration</span>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-1">Autonomy Engine</h1>
        <p class="text-sm text-on-surface-variant mt-2 max-w-2xl">
            Relay agents operate on a hierarchical constraint model. Higher-level rules take precedence,
            ensuring strict human-in-the-loop oversight when it matters most.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach ($hierarchy as $layer)
            <div class="bg-surface-container-low rounded-xl p-4 flex gap-3">
                <span class="font-label text-[10px] text-outline uppercase tracking-widest pt-0.5 shrink-0">LVL {{ $layer['num'] }}</span>
                <div class="min-w-0">
                    <h3 class="font-label text-xs text-primary uppercase tracking-widest">{{ $layer['key'] }}</h3>
                    <p class="text-xs text-on-surface-variant mt-1 leading-snug">{{ $layer['desc'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="bg-surface-container-low rounded-xl p-4 border-l-4 border-secondary">
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">Active Posture · Current Effective Level</span>
        <h2 data-global-level-text class="font-headline text-3xl font-bold text-secondary mt-1">{{ ucfirst($globalDefault->value) }}</h2>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Global Default --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-4">Global Default</h2>
            <p class="text-sm text-on-surface-variant mb-4">The baseline autonomy level applied to all stages unless overridden.</p>

            <form id="global-autonomy-form" method="POST" action="{{ route('config.update-global') }}">
                @csrf
                <div class="space-y-2" data-level-group>
                    @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                        <label data-level="{{ $level->value }}"
                               class="relative block p-3 rounded-md cursor-pointer transition-colors
                               {{ $globalDefault === $level
                                   ? 'bg-surface-container-high border-l-2 border-secondary'
                                   : 'border-l-2 border-transparent hover:bg-surface-container' }}">
                            <input type="radio" name="level" value="{{ $level->value }}" {{ $globalDefault === $level ? 'checked' : '' }}
                                   class="sr-only peer">
                            <div>
                                <span class="font-label text-sm font-bold uppercase tracking-widest {{ $globalDefault === $level ? 'text-secondary' : 'text-on-surface' }}">{{ $level->value }}</span>
                                <p class="text-xs text-on-surface-variant mt-1">
                                    @switch($level)
                                        @case(\App\Enums\AutonomyLevel::Manual)
                                            Every action requires explicit approval. Full human control at each step.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Supervised)
                                            Agents work but pause for approval at stage transitions. Recommended starting point.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Assisted)
                                            Agents auto-advance through stages, pausing only on escalation rule matches.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Autonomous)
                                            Fully autonomous. Agents run the entire pipeline without pausing.
                                            @break
                                    @endswitch
                                </p>
                            </div>
                        </label>
                    @endforeach
                </div>
                @error('level')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const form = document.getElementById('global-autonomy-form');
                    if (!form) return;
                    const group = form.querySelector('[data-level-group]');
                    const selectedCls = ['bg-surface-container-high', 'border-secondary'];
                    const unselectedCls = ['border-transparent', 'hover:bg-surface-container'];

                    const rankOf = { manual: 0, supervised: 1, assisted: 2, autonomous: 3 };
                    const prettify = (v) => v.charAt(0).toUpperCase() + v.slice(1);

                    function paint(level) {
                        const rank = rankOf[level];
                        group.querySelectorAll('label[data-level]').forEach(l => {
                            const active = l.dataset.level === level;
                            l.classList.toggle('bg-surface-container-high', active);
                            l.classList.toggle('border-secondary', active);
                            l.classList.toggle('border-transparent', !active);
                            l.classList.toggle('hover:bg-surface-container', !active);
                            const name = l.querySelector('[data-level-name]') || l.querySelector('.font-label');
                            if (name) {
                                name.classList.toggle('text-secondary', active);
                                name.classList.toggle('text-on-surface', !active);
                            }
                        });

                        document.querySelectorAll('[data-global-level-text]').forEach(el => {
                            el.textContent = prettify(level);
                        });

                        document.querySelectorAll('[data-stage-overrides] [data-stage-form]').forEach(form => {
                            form.querySelectorAll('[data-inherit-global]').forEach(span => {
                                span.textContent = `(${prettify(level)})`;
                            });
                            const input = form.querySelector('[data-level-input]');
                            form.querySelectorAll('button[data-level][data-rank]').forEach(btn => {
                                const pillRank = Number(btn.dataset.rank);
                                const tooLoose = pillRank > rank && pillRank !== -1;
                                btn.classList.toggle('hidden', tooLoose);
                                if (tooLoose && input.value === btn.dataset.level) {
                                    // selected pill became invalid — fall back to inherit
                                    setPill(form, '');
                                }
                            });
                        });
                        syncPreview();
                    }

                    function setPill(form, level) {
                        const input = form.querySelector('[data-level-input]');
                        input.value = level;
                        form.querySelectorAll('button[data-level]').forEach(btn => {
                            const active = btn.dataset.level === level;
                            btn.classList.toggle('bg-secondary-container/30', active);
                            btn.classList.toggle('text-secondary', active);
                            btn.classList.toggle('text-on-surface-variant', !active);
                            btn.classList.toggle('hover:bg-surface-container', !active);
                        });
                        syncPreview();
                    }

                    function effectiveStyle(level) {
                        return {
                            manual:     ['bg-error-container/30', 'text-error'],
                            supervised: ['bg-stage-stuck/20', 'text-stage-stuck'],
                            assisted:   ['bg-primary-container/30', 'text-primary'],
                            autonomous: ['bg-secondary-container/30', 'text-secondary'],
                        }[level];
                    }

                    const allPillBgs = ['bg-error-container/30', 'bg-stage-stuck/20', 'bg-primary-container/30', 'bg-secondary-container/30'];
                    const allPillFgs = ['text-error', 'text-stage-stuck', 'text-primary', 'text-secondary'];

                    function syncPreview() {
                        const panel = document.querySelector('[data-preview-panel]');
                        if (!panel) return;
                        const globalLevel = document.querySelector('#global-autonomy-form input[name=level]:checked')?.value
                            || document.querySelector('label[data-level].bg-surface-container-high')?.dataset.level;
                        if (!globalLevel) return;

                        panel.querySelectorAll('[data-preview-row]').forEach(row => {
                            const stage = row.dataset.stage;
                            const form = document.querySelector(`[data-stage-form][action$="/${stage}"]`);
                            const override = form?.querySelector('[data-level-input]')?.value || '';
                            const effective = override || globalLevel;

                            const src = row.querySelector('[data-preview-source]');
                            if (src) {
                                const overridden = Boolean(override);
                                src.textContent = overridden ? 'stage override' : 'from global';
                                src.classList.toggle('text-primary', overridden);
                                src.classList.toggle('text-outline', !overridden);
                            }

                            const pill = row.querySelector('[data-preview-pill]');
                            if (pill) {
                                pill.classList.remove(...allPillBgs, ...allPillFgs);
                                const [bg, fg] = effectiveStyle(effective);
                                pill.classList.add(bg, fg);
                                pill.textContent = prettify(effective);
                            }
                        });
                    }

                    document.querySelectorAll('[data-stage-overrides] [data-stage-form]').forEach(form => {
                        form.addEventListener('click', async (e) => {
                            const btn = e.target.closest('button[data-level]');
                            if (!btn || btn.classList.contains('hidden')) return;
                            e.preventDefault();
                            const level = btn.dataset.level;
                            setPill(form, level);
                            try {
                                await fetch(form.action, {
                                    method: 'POST',
                                    body: new FormData(form),
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                });
                            } catch (_) {}
                        });
                    });

                    form.addEventListener('change', async (e) => {
                        if (e.target.name !== 'level') return;
                        const level = e.target.value;
                        paint(level);
                        const fd = new FormData(form);
                        try {
                            await fetch(form.action, {
                                method: 'POST',
                                body: fd,
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                redirect: 'manual',
                            });
                        } catch (_) {}
                    });
                });
            </script>
        </div>

        {{-- Per-Stage Overrides --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-2">Per-Stage Overrides</h2>
            <p class="text-sm text-on-surface-variant mb-4">Stage overrides can only <strong>tighten</strong> from the global default (<span data-global-level-text>{{ ucfirst($globalDefault->value) }}</span>). Select "Inherit" to use the global level.</p>

            <div class="space-y-3" data-stage-overrides data-global-order="{{ $globalDefault->order() }}">
                @foreach (\App\Enums\StageName::cases() as $stage)
                    @php $currentOverride = $stageOverrides[$stage->value]; @endphp
                    <form method="POST" action="{{ route('config.update-stage', $stage->value) }}"
                          data-stage-form class="space-y-1">
                        @csrf
                        <input type="hidden" name="level" value="{{ $currentOverride?->value ?? '' }}" data-level-input>
                        <div class="font-label text-[10px] uppercase tracking-widest text-outline">
                            {{ $stage->value }}
                        </div>
                        <div class="flex rounded-lg overflow-hidden bg-surface-container-lowest divide-x divide-outline-variant/30">
                            @php
                                $shortLabel = [
                                    'manual' => 'Manual',
                                    'supervised' => 'Super',
                                    'assisted' => 'Assist',
                                    'autonomous' => 'Auto',
                                ];
                                $pills = [['value' => '', 'label' => 'Inherit', 'rank' => -1, 'isInherit' => true]];
                                foreach (\App\Enums\AutonomyLevel::cases() as $lvl) {
                                    $pills[] = ['value' => $lvl->value, 'label' => $shortLabel[$lvl->value] ?? $lvl->value, 'rank' => $lvl->order(), 'isInherit' => false];
                                }
                            @endphp
                            @foreach ($pills as $pill)
                                @php
                                    $isActive = $pill['isInherit']
                                        ? $currentOverride === null
                                        : $currentOverride?->value === $pill['value'];
                                    $isHidden = ! $pill['isInherit'] && $pill['rank'] > $globalDefault->order();
                                @endphp
                                <button type="button"
                                        data-level="{{ $pill['value'] }}"
                                        data-rank="{{ $pill['rank'] }}"
                                        @class([
                                            'flex-1 font-label text-[10px] uppercase tracking-wider px-1.5 py-2 transition-colors',
                                            'bg-secondary-container/30 text-secondary' => $isActive,
                                            'text-on-surface-variant hover:bg-surface-container' => ! $isActive,
                                            'hidden' => $isHidden,
                                        ])>
                                    {{ $pill['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </form>
                @endforeach
            </div>
            @error('level')
                <p class="mt-2 text-sm text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Escalation Rules --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-headline font-medium">Escalation Rules</h2>
                    <p class="text-sm text-on-surface-variant mt-1">Rules force tighter autonomy when conditions match before stage transitions.</p>
                </div>
                <button type="button" data-open-rule-modal class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">Add Rule</button>
            </div>

            <div class="space-y-2 {{ $rules->isEmpty() ? 'hidden' : '' }}" data-rules-list>
                @foreach ($rules as $rule)
                    @include('escalation-rules._card', ['rule' => $rule, 'order' => $loop->iteration])
                @endforeach
            </div>
            <p data-rules-empty class="text-sm text-outline {{ $rules->isEmpty() ? '' : 'hidden' }}">
                No escalation rules configured.
            </p>
        </div>

        {{-- Rule Modal --}}
        <div id="rule-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" aria-modal="true" role="dialog">
            <div class="bg-surface-container-low w-full md:max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl shadow-2xl shadow-black/60">
                <div class="flex items-center justify-between px-5 py-4 sticky top-0 bg-surface-container-low">
                    <h2 id="rule-modal-title" class="font-headline text-xl font-bold text-on-surface">Add Escalation Rule</h2>
                    <button type="button" data-close-rule-modal class="text-outline hover:text-on-surface text-2xl leading-none" aria-label="Close">×</button>
                </div>
                <form id="rule-form" method="POST" action="{{ route('escalation-rules.store') }}" class="px-5 pb-5 space-y-4">
                    @csrf
                    <input type="hidden" name="_method" value="POST" data-method-input>

                    <div data-rule-errors class="hidden rounded-md bg-error-container/20 border-l-4 border-error p-3 font-label text-xs text-error space-y-1"></div>

                    <div>
                        <label for="rule-name" class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Rule Name</label>
                        <input type="text" name="name" id="rule-name" required
                               class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                               placeholder="e.g., Security-sensitive paths">
                    </div>

                    <div>
                        <label for="rule-condition-type" class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Condition Type</label>
                        <select name="condition_type" id="rule-condition-type" required
                                class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                            <option value="label_match">Label Match</option>
                            <option value="file_path_match">File Path Match</option>
                            <option value="diff_size">Diff Size (threshold)</option>
                            <option value="touched_directory_match">Touched Directory Match</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Condition Value</label>
                        <div class="flex gap-2">
                            <select name="condition_operator" id="rule-condition-operator"
                                    class="hidden w-20 shrink-0 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-2 py-2 text-sm font-label focus:border-primary focus:ring-primary">
                                <option value=">=">≥</option>
                                <option value=">">&gt;</option>
                                <option value="<=">≤</option>
                                <option value="<">&lt;</option>
                                <option value="=">=</option>
                            </select>
                            <input type="text" name="condition_value" id="rule-condition-value" required
                                   class="flex-1 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                                   placeholder="e.g., security, src/config/*, 500, database/">
                        </div>
                        <p id="rule-condition-hint" class="font-label text-[10px] text-outline uppercase tracking-widest mt-1 leading-snug">
                            Labels: exact name · File paths: glob · Diff size: line-count threshold · Directories: path prefix
                        </p>
                    </div>

                    <div>
                        <label for="rule-target-level" class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Target Autonomy Level</label>
                        <select name="target_level" id="rule-target-level" required
                                class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                            @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                                <option value="{{ $level->value }}">{{ ucfirst($level->value) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <button type="submit" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90" data-submit-label>
                            Create Rule
                        </button>
                        <button type="button" data-close-rule-modal class="rounded-md bg-surface-container-high text-on-surface px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const list = document.querySelector('[data-rules-list]');
                const empty = document.querySelector('[data-rules-empty]');
                const modal = document.getElementById('rule-modal');
                const modalTitle = document.getElementById('rule-modal-title');
                const modalForm = document.getElementById('rule-form');
                const submitLabel = modalForm.querySelector('[data-submit-label]');
                const methodInput = modalForm.querySelector('[data-method-input]');
                const errorsBox = modalForm.querySelector('[data-rule-errors]');
                const storeAction = '{{ route('escalation-rules.store') }}';
                const updateUrl = (id) => `/escalation-rules/${id}`;

                function renumber() {
                    list.querySelectorAll('[data-rule-card]').forEach((card, i) => {
                        const badge = card.querySelector('[data-rule-order]');
                        if (badge) badge.textContent = String(i + 1);
                    });
                }

                function toggleEmptyState() {
                    const hasRules = list.children.length > 0;
                    list.classList.toggle('hidden', !hasRules);
                    empty.classList.toggle('hidden', hasRules);
                }

                async function post(url, fd) {
                    return fetch(url, {
                        method: 'POST',
                        body: fd,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                }

                const typeSel = modalForm.querySelector('#rule-condition-type');
                const opSel = modalForm.querySelector('#rule-condition-operator');
                const valInput = modalForm.querySelector('#rule-condition-value');
                const hint = modalForm.querySelector('#rule-condition-hint');

                function applyTypeUI(type, reset = false) {
                    const isDiff = type === 'diff_size';
                    opSel.classList.toggle('hidden', !isDiff);
                    valInput.placeholder = {
                        label_match: 'e.g., security',
                        file_path_match: 'e.g., app/Services/Auth/*',
                        diff_size: 'e.g., 500',
                        touched_directory_match: 'e.g., database/migrations',
                    }[type] || '';
                    hint.textContent = {
                        label_match: 'Match any issue label exactly',
                        file_path_match: 'Glob pattern against changed file paths',
                        diff_size: 'Compare total line count of the diff',
                        touched_directory_match: 'Match directory path prefix',
                    }[type] || '';
                    valInput.type = isDiff ? 'number' : 'text';
                    valInput.min = isDiff ? '0' : null;
                    valInput.step = isDiff ? '1' : null;
                    if (reset) {
                        valInput.value = '';
                        opSel.value = isDiff ? '>=' : '~';
                    }
                }
                typeSel.addEventListener('change', (e) => applyTypeUI(e.target.value, true));

                function openModal(rule) {
                    errorsBox.classList.add('hidden');
                    errorsBox.innerHTML = '';
                    if (rule) {
                        modalTitle.textContent = 'Edit Escalation Rule';
                        submitLabel.textContent = 'Update Rule';
                        modalForm.action = updateUrl(rule.id);
                        methodInput.value = 'PUT';
                        modalForm.querySelector('#rule-name').value = rule.name || '';
                        typeSel.value = rule.conditionType || 'label_match';
                        opSel.value = rule.conditionOperator || '>=';
                        valInput.value = rule.conditionValue || '';
                        modalForm.querySelector('#rule-target-level').value = rule.targetLevel || 'manual';
                    } else {
                        modalTitle.textContent = 'Add Escalation Rule';
                        submitLabel.textContent = 'Create Rule';
                        modalForm.action = storeAction;
                        methodInput.value = 'POST';
                        modalForm.reset();
                        typeSel.value = 'label_match';
                    }
                    applyTypeUI(typeSel.value);
                    modal.classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                }

                function closeModal() {
                    modal.classList.add('hidden');
                    document.body.style.overflow = '';
                }

                // Open triggers
                document.querySelectorAll('[data-open-rule-modal]').forEach(btn => {
                    btn.addEventListener('click', () => openModal(null));
                });

                // Close triggers
                document.querySelectorAll('[data-close-rule-modal]').forEach(btn => {
                    btn.addEventListener('click', closeModal);
                });
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeModal();
                });

                // Modal form submit — fetch + insert/replace card
                modalForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    errorsBox.classList.add('hidden');
                    errorsBox.innerHTML = '';
                    const res = await post(modalForm.action, new FormData(modalForm));
                    if (res.status === 422) {
                        try {
                            const json = await res.json();
                            const msgs = [];
                            Object.values(json.errors || {}).forEach(arr => arr.forEach(m => msgs.push(m)));
                            errorsBox.innerHTML = msgs.map(m => `<p>${m.replace(/</g,'&lt;')}</p>`).join('');
                            errorsBox.classList.remove('hidden');
                        } catch (_) {}
                        return;
                    }
                    if (!res.ok) return;
                    const json = await res.json();
                    const wrap = document.createElement('div');
                    wrap.innerHTML = json.html.trim();
                    const newCard = wrap.firstElementChild;
                    const existing = list.querySelector(`[data-rule-card][data-rule-id="${json.id}"]`);
                    if (existing) existing.replaceWith(newCard);
                    else list.appendChild(newCard);
                    renumber();
                    toggleEmptyState();
                    closeModal();
                });

                // Card-level actions: delete, toggle, move, edit
                list.addEventListener('submit', async (e) => {
                    const form = e.target.closest('form');
                    if (!form) return;
                    const card = form.closest('[data-rule-card]');
                    if (!card) return;

                    if (form.matches('[data-rule-delete]')) {
                        e.preventDefault();
                        if (!confirm('Delete this rule?')) return;
                        await post(form.action, new FormData(form));
                        card.remove();
                        renumber();
                        toggleEmptyState();
                        return;
                    }

                    const submitter = e.submitter || form.querySelector('button[type=submit]');

                    if (submitter?.matches('[data-rule-toggle]')) {
                        e.preventDefault();
                        const res = await post(form.action, new FormData(form));
                        try {
                            const json = await res.json();
                            const enabled = !!json.enabled;
                            submitter.textContent = enabled ? 'Enabled' : 'Disabled';
                            submitter.classList.toggle('text-secondary', enabled);
                            submitter.classList.toggle('text-outline', !enabled);
                            card.classList.toggle('opacity-50', !enabled);
                        } catch (_) {}
                        return;
                    }

                    if (submitter?.matches('[data-rule-move]')) {
                        e.preventDefault();
                        const dir = submitter.dataset.ruleMove;
                        const sibling = dir === 'up' ? card.previousElementSibling : card.nextElementSibling;
                        if (!sibling || !sibling.matches('[data-rule-card]')) return;
                        if (dir === 'up') list.insertBefore(card, sibling);
                        else list.insertBefore(sibling, card);
                        renumber();
                        await post(form.action, new FormData(form));
                        return;
                    }
                });

                list.addEventListener('click', (e) => {
                    const btn = e.target.closest('[data-rule-edit]');
                    if (!btn) return;
                    const card = btn.closest('[data-rule-card]');
                    openModal({
                        id: card.dataset.ruleId,
                        name: card.dataset.ruleName,
                        conditionType: card.dataset.ruleConditionType,
                        conditionOperator: card.dataset.ruleConditionOperator,
                        conditionValue: card.dataset.ruleConditionValue,
                        targetLevel: card.dataset.ruleTargetLevel,
                    });
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
                });
            });
        </script>

        {{-- Iteration Cap --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-2">Iteration Cap</h2>
            <p class="text-sm text-on-surface-variant mb-4">Maximum Verify-to-Implement bounce cycles before an issue is marked stuck.</p>

            <form method="POST" action="{{ route('config.update-iteration-cap') }}" class="flex items-center gap-3 flex-wrap">
                @csrf
                <input type="number" name="iteration_cap" value="{{ $iterationCap }}" min="1" max="20"
                    class="iteration-cap-input w-20 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 focus:border-primary focus:ring-primary">
                <button type="submit" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">Save</button>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">Min 1 · Max 20</span>
            </form>
            <style>
                .iteration-cap-input::-webkit-outer-spin-button,
                .iteration-cap-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
                .iteration-cap-input { -moz-appearance: textfield; }
            </style>
            @error('iteration_cap')
                <p class="mt-2 text-sm text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Preview Panel --}}
    <div class="lg:col-span-1">
        <div class="bg-surface-container-low rounded-xl p-6 sticky top-6">
            <h2 class="text-lg font-headline font-medium mb-2">Effective Autonomy Preview</h2>
            <p class="text-sm text-on-surface-variant mb-4">Shows the resolved autonomy level for a sample issue at each stage, before escalation rules.</p>

            <div class="space-y-3" data-preview-panel>
                @foreach (\App\Enums\StageName::cases() as $stage)
                    @php
                        $effective = $stageOverrides[$stage->value]?->value ?? $globalDefault->value;
                        $isOverridden = $stageOverrides[$stage->value] !== null;
                    @endphp
                    <div class="flex items-center justify-between p-3 rounded-md bg-surface-container"
                         data-preview-row data-stage="{{ $stage->value }}">
                        <div>
                            <div class="text-sm font-medium">{{ ucfirst($stage->value) }}</div>
                            <div data-preview-source class="text-xs {{ $isOverridden ? 'text-primary' : 'text-outline' }}">
                                {{ $isOverridden ? 'stage override' : 'from global' }}
                            </div>
                        </div>
                        <span data-preview-pill class="inline-flex items-center px-2.5 py-1 rounded-md font-label text-[10px] uppercase tracking-widest
                            @if ($effective === 'manual') bg-error-container/30 text-error
                            @elseif ($effective === 'supervised') bg-stage-stuck/20 text-stage-stuck
                            @elseif ($effective === 'assisted') bg-primary-container/30 text-primary
                            @else bg-secondary-container/30 text-secondary
                            @endif">
                            {{ ucfirst($effective) }}
                        </span>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</div>
</x-layouts.app>
