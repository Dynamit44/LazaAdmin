from pathlib import Path
import sys

root = Path('.')
target = root / 'admin' / 'organizer' / 'schedule.html'
if not target.exists():
    print(f'ERROR: file not found: {target}')
    sys.exit(1)

text = target.read_text(encoding='utf-8')

old = """    $('out')?.addEventListener('click', async (e)=>{
      if (!manualMode || !clickMoveMode || dropInProgress || !lastJson?.ok) return;

      const matchEl = e.target.closest('.match-draggable, .match');
      if (matchEl && matchEl.dataset.mid) {
        const mid = Number(matchEl.dataset.mid || 0);
        if (!mid) return;

        if (selectedMatchId === mid) {
          clearSelectedManualMatch();
          return;
        }

        const td = matchEl.closest('td[data-date][data-slot-index][data-field]');
        selectedMatchId = mid;
        selectedFromCell = td ? {
          date: td.dataset.date,
          slot_local: Number(td.dataset.slot || 0),
          slot_index: Number(td.dataset.slotIndex || 0),
          field_code: td.dataset.field,
          time: normTime(cellTimeForDrop(td) || ''),
          day_start: td.dataset.dayStart || '',
          day_end: td.dataset.dayEnd || ''
        } : null;

        clearDropMarks();
        clearManualIssues();
        markSelectedManualMatch(mid);
        renderSelectedMoveBar();
        return;
      }

      const td = e.target.closest('td[data-date][data-slot-index][data-field]');
      if (td && selectedMatchId) {
        await applyManualMoveOrSwap(selectedMatchId, selectedFromCell, td);
      }
    });
"""

new = """    $('out')?.addEventListener('click', async (e)=>{
      if (!manualMode || !clickMoveMode || dropInProgress || !lastJson?.ok) return;

      const td = e.target.closest('td[data-date][data-slot-index][data-field]');
      const matchEl = e.target.closest('.match-draggable, .match');
      const clickedMid = Number(matchEl?.dataset.mid || 0);

      // Если матч уже выбран — следующий клик по ЛЮБОЙ ячейке = попытка переноса / swap.
      // Исключение: повторный клик по той же самой карточке снимает выбор.
      if (selectedMatchId && td) {
        if (clickedMid && clickedMid === selectedMatchId) {
          clearSelectedManualMatch();
          return;
        }
        await applyManualMoveOrSwap(selectedMatchId, selectedFromCell, td);
        return;
      }

      // Если ещё ничего не выбрано — выбираем матч.
      if (matchEl && clickedMid) {
        const srcTd = matchEl.closest('td[data-date][data-slot-index][data-field]');
        selectedMatchId = clickedMid;
        selectedFromCell = srcTd ? {
          date: srcTd.dataset.date,
          slot_local: Number(srcTd.dataset.slot || 0),
          slot_index: Number(srcTd.dataset.slotIndex || 0),
          field_code: srcTd.dataset.field,
          time: normTime(cellTimeForDrop(srcTd) || ''),
          day_start: srcTd.dataset.dayStart || '',
          day_end: srcTd.dataset.dayEnd || ''
        } : null;

        clearDropMarks();
        clearManualIssues();
        markSelectedManualMatch(clickedMid);
        renderSelectedMoveBar();
      }
    });
"""

if old not in text:
    print('ERROR: target click-handler block not found. File state is not the expected one.')
    sys.exit(2)

backup = target.with_suffix(target.suffix + '.pre_click_move_swap_fix.bak')
backup.write_text(text, encoding='utf-8')
text = text.replace(old, new, 1)
target.write_text(text, encoding='utf-8')

print('OK: click handler updated')
print(f'Backup: {backup}')
print(f'Updated: {target}')
