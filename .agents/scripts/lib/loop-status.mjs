// Current-task-scoped review-round accounting.
//
// Pure and dependency-free. Each archived verdict written from TASK-0002
// onwards carries a `task` field (the active task id, taken from
// .agents/current-task.md). This module counts ONLY the current task's rounds
// so a "> N rounds" warning reflects the task in hand, not the lifetime of the
// archive directory. Legacy archives (bootstrap, TASK-0001) have no `task`
// field and are simply not counted toward any task.

/**
 * Extract the active task id from .agents/current-task.md.
 * Prefers an explicit `<!-- task-id: TASK-xxxx -->` marker; falls back to the
 * first `# TASK-xxxx` heading. Returns null when neither is present.
 *
 * @param {string} currentTaskMarkdown
 * @returns {string|null}
 */
export function currentTaskId(currentTaskMarkdown) {
  const s = String(currentTaskMarkdown || '');
  const marker = s.match(/<!--\s*task-id:\s*(TASK-[A-Za-z0-9._-]+)\s*-->/i);
  if (marker) return marker[1].toUpperCase();
  const heading = s.match(/^#{1,6}\s+(TASK-[A-Za-z0-9._-]+)\b/im);
  if (heading) return heading[1].toUpperCase();
  return null;
}

/**
 * @typedef {{ name?: string, verdict?: string, task?: string|null, findings?: any[], required_fixes?: any[] }} ArchivedVerdict
 */

/**
 * Summarise how many review rounds belong to the current task.
 *
 * A round "belongs" to the task when its `task` field equals `taskId` exactly
 * (order-independent - robust to any archive interleaving). When `taskId` is
 * null the count cannot be scoped, so `taskRounds` is null and callers must not
 * emit a round-budget warning.
 *
 * @param {ArchivedVerdict[]} archives  chronological, oldest first
 * @param {string|null} taskId
 * @returns {{taskId: string|null, taskRounds: number|null, total: number, rounds: ArchivedVerdict[], untaggedArchives: number}}
 */
export function taskRoundSummary(archives, taskId) {
  const list = Array.isArray(archives) ? archives : [];
  const total = list.length;
  const untaggedArchives = list.filter((a) => a == null || a.task == null).length;

  if (!taskId) {
    return { taskId: null, taskRounds: null, total, rounds: [], untaggedArchives };
  }

  const rounds = list.filter((a) => a && a.task === taskId);
  return { taskId, taskRounds: rounds.length, total, rounds, untaggedArchives };
}
