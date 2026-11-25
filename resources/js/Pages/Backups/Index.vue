<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { CalendarClock, DatabaseBackup, Download, HardDrive, ShieldAlert, SlidersHorizontal } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import TableAction from '../../Components/TableAction.vue'
import { confirmAction, confirmDeleteThen } from '../../lib/swal'

defineOptions({ layout: AppLayout })

/**
 * Backups (phase 35): make one now, download it, delete an old one.
 *
 * A backup is the whole books and every password hash, so the screen keeps
 * saying where it belongs — downloaded and kept off the server. Restoring is
 * never done from here; every backup carries RESTORE.txt.
 */
const props = defineProps({
    backups: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    available: { type: Boolean, default: true },
    can: { type: Object, default: () => ({}) },
})

const making = ref(false)

async function makeBackup() {
    const sure = await confirmAction({
        title: 'Make a backup now?',
        text: 'The whole database' + (props.settings.include_files ? ', with the product photos and the logo,' : '')
            + ' is saved on the server. It takes a few seconds; keep this page open.',
        confirmText: 'Make a backup',
    })

    if (!sure) return

    router.post('/backups', {}, {
        preserveScroll: true,
        onStart: () => (making.value = true),
        onFinish: () => (making.value = false),
    })
}

function remove(backup) {
    confirmDeleteThen(`/backups/${backup.name}`, {
        title: 'Delete this backup?',
        name: backup.name,
        successMessage: null,
    })
}
</script>

<template>
    <div>
        <PageHeader heading="Backups" subheading="A copy of the whole system: every record, and the product photos and logo.">
            <template #actions>
                <PrimaryButton v-if="can.settings" variant="secondary" href="/settings/backup">
                    <SlidersHorizontal :size="15" />
                    Backup settings
                </PrimaryButton>
                <PrimaryButton v-if="can.create" :loading="making" :disabled="!available" @click="makeBackup">
                    <DatabaseBackup v-if="!making" :size="15" />
                    {{ making ? 'Making a backup…' : 'Make a backup now' }}
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="mb-4 grid gap-4 lg:grid-cols-3">
            <section class="card p-5 lg:col-span-2">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-warning-50 text-warning-700">
                        <ShieldAlert :size="18" />
                    </span>
                    <div class="text-sm text-ink">
                        <p class="font-semibold">Keep a copy off the server.</p>
                        <p class="mt-1 text-ink-muted">
                            A backup kept only on the server is lost with the server. Download the newest one regularly and
                            keep it where only the owner can reach it: it holds the whole books and every user's password hash.
                        </p>
                        <p class="mt-2 text-ink-muted">
                            It does not hold the <code class="rounded bg-surface-sunken px-1 font-mono text-xs">.env</code> file.
                            Keep that apart: the restored system needs the same app key. Every backup carries
                            <span class="font-medium text-ink">RESTORE.txt</span>, which says how to put it back.
                        </p>
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start gap-2.5">
                        <CalendarClock :size="16" class="mt-0.5 shrink-0 text-ink-subtle" />
                        <div>
                            <dt class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">By itself</dt>
                            <dd class="text-ink">{{ settings.frequency_label }}</dd>
                            <dd v-if="settings.frequency !== 'off'" class="text-2xs text-ink-subtle">
                                Only if the server runs <code class="font-mono">php artisan schedule:run</code> every minute.
                            </dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <HardDrive :size="16" class="mt-0.5 shrink-0 text-ink-subtle" />
                        <div>
                            <dt class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Kept on the server</dt>
                            <dd class="text-ink">The newest {{ settings.keep_copies }}</dd>
                            <dd class="text-2xs text-ink-subtle">
                                {{ settings.include_files ? 'With the product photos and the logo.' : 'The database only — no photos.' }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </section>
        </div>

        <p v-if="!available" class="card mb-4 p-4 text-sm text-warning-700">
            Backups cannot be made: the server's PHP has no zip extension. Ask the host to switch on "zip".
        </p>

        <section class="card overflow-hidden">
            <div class="border-b border-line px-5 py-3.5">
                <h3 class="text-sm font-semibold text-ink">On the server</h3>
            </div>

            <p v-if="!backups.length" class="px-5 py-10 text-center text-sm text-ink-subtle">
                No backup yet. Make the first one now.
            </p>

            <ul v-else class="divide-y divide-line">
                <li
                    v-for="(backup, index) in backups"
                    :key="backup.name"
                    class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-surface-sunken/60"
                >
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">
                            {{ backup.name }}
                            <span v-if="index === 0" class="ml-2 rounded-full bg-success-50 px-2 py-0.5 font-sans text-2xs font-semibold text-success-700">Newest</span>
                        </p>
                        <p class="text-xs text-ink-subtle">{{ backup.made_at }} · {{ backup.size_label }}</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <!-- A plain link: it downloads a file, not a page -->
                        <a
                            v-if="can.download"
                            :href="`/backups/${backup.name}/download`"
                            :title="`Download ${backup.name}`"
                            :aria-label="`Download ${backup.name}`"
                            class="grid size-8 place-items-center rounded-field bg-info-50 text-info-700 ring-1 ring-info-500/20 transition-all duration-150 ring-inset hover:scale-105 hover:bg-info-500/15 active:scale-95"
                        >
                            <Download :size="15" :stroke-width="2" aria-hidden="true" />
                        </a>
                        <TableAction v-if="can.delete" variant="delete" :label="`Delete ${backup.name}`" @click="remove(backup)" />
                    </div>
                </li>
            </ul>
        </section>

        <p class="mt-4 text-xs text-ink-subtle">
            Restoring is done by hand, never from this screen: import <span class="font-mono">database.sql</span> into an empty
            database, copy <span class="font-mono">files/</span> into storage, then run
            <span class="font-mono">php artisan inventory:verify</span>. See
            <Link v-if="can.settings" href="/settings/backup" class="text-brand-700 hover:underline">Backup settings</Link><template v-else>Backup settings</template>
            for how often one is made and how many are kept.
        </p>
    </div>
</template>
