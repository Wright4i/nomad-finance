<script lang="ts">
  import { tick } from 'svelte';
  import DateField from './DateField.svelte';
  import Note from './Note.svelte';
  import { api, explain } from '../lib/api';
  import type { CalendarEntry } from '../lib/types';

  let {
    open = $bindable(false),
    entry = null,
    onsaved,
  }: {
    open?: boolean;
    entry?: CalendarEntry | null;
    onsaved?: () => void | Promise<void>;
  } = $props();

  let dialog = $state<HTMLDialogElement | undefined>();
  let text = $state('');
  let startOn = $state('');
  let endOn = $state('');
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let seen = false;

  $effect(() => {
    const showing = open;
    const current = entry;
    if (!showing) {
      seen = false;
      return;
    }
    if (seen) return;
    seen = true;
    text = current?.text ?? '';
    startOn = current?.startOn ?? '';
    endOn = current?.endOn ?? '';
    fields = {};
    message = '';
    void tick().then(() => {
      if (!open || dialog?.open) return;
      dialog?.showModal();
    });
  });

  function shut(): void {
    open = false;
    if (dialog?.open) dialog.close();
  }

  async function save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (saving) return;
    saving = true;
    message = '';
    fields = {};
    const body = { text, startOn, endOn };
    try {
      if (entry) {
        await api(`/api/calendar-entries/${entry.id}`, { method: 'PATCH', body });
      } else {
        await api('/api/calendar-entries', { method: 'POST', body });
      }
      shut();
      await onsaved?.();
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  async function remove(): Promise<void> {
    if (!entry || saving) return;
    saving = true;
    message = '';
    try {
      await api(`/api/calendar-entries/${entry.id}/delete`, { method: 'POST', body: {} });
      shut();
      await onsaved?.();
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }
</script>

<dialog class="modal wide" bind:this={dialog} aria-labelledby="entry-title" onclose={() => (open = false)}>
  <form onsubmit={save}>
    <div class="modal-body">
      <h2 id="entry-title" class="m-0 text-2xl">{entry ? 'Edit entry' : 'Add entry'}</h2>
      <Note bind:message />
      <label class="field">
        <span>Text</span>
        <input name="text" bind:value={text} />
        {#if fields.text}<em>{fields.text}</em>{/if}
      </label>
      <div class="flex gap-2">
        <label class="field flex-1">
          <span>Start date</span>
          <DateField name="startOn" bind:value={startOn} />
          {#if fields.startOn}<em>{fields.startOn}</em>{/if}
        </label>
        <label class="field flex-1">
          <span>End date</span>
          <DateField name="endOn" bind:value={endOn} />
          {#if fields.endOn}<em>{fields.endOn}</em>{/if}
        </label>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn primary" type="submit" disabled={saving}>Save</button>
      {#if entry}
        <button class="btn danger" type="button" disabled={saving} onclick={remove}>Delete</button>
      {/if}
      <button class="btn" type="button" onclick={shut}>Close</button>
    </div>
  </form>
</dialog>
