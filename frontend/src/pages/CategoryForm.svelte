<script lang="ts">
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { exponentOf, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { fieldTips } from '../lib/fieldTips';
  import { go } from '../lib/nav.svelte.ts';
  import { tip } from '../lib/tip';
  import { session } from '../lib/session.svelte.ts';
  import type { Category } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const groups = [
    { id: 'needs', label: 'Needs' },
    { id: 'wants', label: 'Wants' },
    { id: 'savings', label: 'Savings' },
  ] as const;

  const targetKinds = [
    { id: 'none', label: 'None' },
    { id: 'balance', label: 'Target balance' },
  ] as const;

  let name = $state('');
  let group = $state('needs');
  let role = $state<'goal' | 'locked'>('goal');
  let locked = $derived(role === 'locked');
  let targetKind = $state<'none' | 'balance'>('none');
  let targetAmount = $state('');
  let archivedAt = $state<string | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let ready = $state(false);
  let dialog: HTMLDialogElement | undefined = $state();
  let deleteDialog: HTMLDialogElement | undefined = $state();

  onMount(async () => {
    try {
      if (!id) return;
      const data = await api<{ category: Category }>(`/api/buckets/${id}?today=${todayISO()}`);
      name = data.category.name;
      group = data.category.group;
      role = data.category.role;
      archivedAt = data.category.archivedAt;
      if (data.category.target?.kind === 'balance') {
        targetKind = 'balance';
        targetAmount = data.category.target.amountMinor === null
          ? ''
          : minorToInput(data.category.target.amountMinor, exponentOf(session.user?.baseCurrency ?? 'USD'));
      }
    } catch (err) {
      message = explain(err).message;
    } finally {
      ready = true;
    }
  });

  function targetBody(): Record<string, unknown> | null {
    if (targetKind === 'none') return null;
    const amountMinor = parseMajor(targetAmount, exponentOf(session.user?.baseCurrency ?? 'USD'));
    return { kind: 'balance', amountMinor };
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    saving = true;
    try {
      const path = id ? `/api/buckets/${id}` : '/api/buckets';
      const method = id ? 'PATCH' : 'POST';
      const target = locked ? (targetAmount.trim() === '' ? null : { kind: 'balance', amountMinor: parseMajor(targetAmount, exponentOf(session.user?.baseCurrency ?? 'USD')) }) : targetBody();
      const body = locked
        ? { today: todayISO(), target }
        : { name, group, today: todayISO(), target };
      await api(path, { method, body });
      go('/budget');
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  function askArchive(): void {
    dialog?.showModal();
  }

  function closeArchive(): void {
    dialog?.close();
  }

  async function archive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/buckets/${id}/archive`, { method: 'POST', body: {} });
      dialog?.close();
      go('/budget');
    } catch (err) {
      dialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  function askDelete(): void {
    deleteDialog?.showModal();
  }

  async function remove(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/buckets/${id}/delete`, { method: 'POST', body: { confirm: true } });
      deleteDialog?.close();
      go('/budget');
    } catch (err) {
      deleteDialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function unarchive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/buckets/${id}/unarchive`, { method: 'POST', body: {} });
      go('/budget');
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? name || 'Edit bucket' : 'New bucket'}</h1>
  <Note bind:message />
  <div class="form-body grid gap-4" aria-busy={id != null && !ready} inert={id != null && !ready}>
  {#if locked}
    <p class="m-0">Name <strong>{name}</strong></p>
  {:else}
    <label class="field">
      <span>Name</span>
      <input name="name" bind:value={name} />
      {#if fields.name}<em>{fields.name}</em>{/if}
    </label>
  {/if}
  {#if !locked}
  <div class="field">
    <span use:tip={fieldTips.bucketGroup}>Group</span>
    <div class="flex flex-wrap gap-2">
      {#each groups as option}
        <button
          class="choice"
          class:on={group === option.id}
          type="button"
          style={`--tone: var(--tone-${option.id})`}
          aria-pressed={group === option.id}
          onclick={() => (group = option.id)}
        >
          <Glyph name={option.id} mini />
          {option.label}
        </button>
      {/each}
    </div>
    {#if fields.group}<em>{fields.group}</em>{/if}
  </div>
  {/if}
  {#if !locked}
  <div class="field">
    <span use:tip={fieldTips.bucketTarget}>Target</span>
    <div class="flex flex-wrap gap-2">
      {#each targetKinds as option}
        <button
          class="choice"
          class:on={targetKind === option.id}
          type="button"
          aria-pressed={targetKind === option.id}
          onclick={() => (targetKind = option.id)}
        >{option.label}</button>
      {/each}
    </div>
    {#if fields.target}<em>{fields.target}</em>{/if}
  </div>
  {/if}
  {#if !locked && targetKind === 'balance'}
    <label class="field">
      <span use:tip={fieldTips.bucketAmount}>Amount</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <input name="targetAmount" inputmode="decimal" bind:value={targetAmount} />
      </span>
      {#if fields.targetAmount}<em>{fields.targetAmount}</em>{/if}
    </label>
  {/if}
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save bucket'}</button>
    <a class="btn" href="/budget" onclick={(event) => { event.preventDefault(); go('/budget'); }}>Cancel</a>
    {#if id && !archivedAt && !locked}
      <button class="btn danger" type="button" title="Hide this bucket. Refused while it holds an amount." disabled={saving} onclick={askArchive}>Archive</button>
    {/if}
    {#if id && archivedAt}
      <button class="btn" type="button" title="Put this bucket back on the list." disabled={saving} onclick={unarchive}>Unarchive</button>
      <button class="btn danger" type="button" title="Remove this archived bucket." disabled={saving} onclick={askDelete}>Delete</button>
    {/if}
  </div>
  {#if id && !ready}
    <div class="form-cover" aria-busy="true">
      <Spinner />
    </div>
  {/if}
  </div>
</form>

<dialog class="modal" bind:this={dialog} aria-labelledby="archive-title">
  <div class="modal-body">
    <h2 id="archive-title" class="text-xl m-0">Archive this bucket?</h2>
    <p class="m-0">Archiving hides the name. The bucket stays in the books.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={closeArchive}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={archive}>Archive bucket</button>
  </div>
</dialog>

<dialog class="modal" bind:this={deleteDialog} aria-labelledby="delete-title">
  <div class="modal-body">
    <h2 id="delete-title" class="text-xl m-0">Delete this bucket?</h2>
    <p class="m-0">This removes the archived bucket. It does not come back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => deleteDialog?.close()}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={remove}>Delete bucket</button>
  </div>
</dialog>
