from pathlib import Path
import sys

p = Path(r"G:\OSPanel\home\lazacup.local\admin\organizer\schedule.html")
if len(sys.argv) > 1:
    p = Path(sys.argv[1])

text = p.read_text(encoding="utf-8")

def ensure_contains(s: str):
    if s not in text:
        raise SystemExit(f"Не найден фрагмент:\n{s[:160]}")

def replace_once(old: str, new: str):
    global text
    ensure_contains(old)
    text = text.replace(old, new, 1)

# 1) CSS
replace_once(
""".lc-event-badge{display:inline-block;padding:2px 6px;border-radius:999px;background:rgba(23,63,105,.08);font-size:11px;}
  </style>""",
""".lc-event-badge{display:inline-block;padding:2px 6px;border-radius:999px;background:rgba(23,63,105,.08);font-size:11px;}
.match.is-selected-manual{outline:3px solid #1e87f0;outline-offset:2px;border-radius:6px;}
.lc-click-move-bar{position:fixed;right:16px;bottom:16px;z-index:1200;background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:10px 12px;box-shadow:0 10px 25px rgba(0,0,0,.12);max-width:420px;}
  </style>"""
)

# 2) HTML bar
replace_once(
"""<div id="manualIssues" class="uk-margin-small-top"></div>""",
"""<div id="manualIssues" class="uk-margin-small-top"></div>
<div id="clickMoveBar" class="lc-click-move-bar" hidden></div>"""
)

# 3) state vars
replace_once(
"""  let manualMode = false;
  let dragMatchId = 0;
  let lastDropCell = null;
  let previewTimer = null;
""",
"""  let manualMode = false;
  let dragMatchId = 0;
  let lastDropCell = null;
  let previewTimer = null;
  let dropInProgress = false;
  let clickMoveMode = true;
  let selectedMatchId = 0;
  let selectedFromCell = null;
"""
)

# 4) helper functions after clearDropMarks
replace_once(
"""  function clearDropMarks(){
    document.querySelectorAll('.drop-ok,.drop-bad').forEach(el=>{
      el.classList.remove('drop-ok','drop-bad');
      el.removeAttribute('title');
    });
  }

  async function movePreview(move, assignmentsOverride=null){
""",
"""  function clearDropMarks(){
    document.querySelectorAll('.drop-ok,.drop-bad').forEach(el=>{
      el.classList.remove('drop-ok','drop-bad');
      el.removeAttribute('title');
    });
  }

  function renderSelectedMoveBar() {
    const box = $('clickMoveBar');
    if (!box) return;

    if (!manualMode || !clickMoveMode || !selectedMatchId) {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }

    const a = findAssignmentByMatchId(lastJson, selectedMatchId);
    const code = a?.code_view || a?.code || ('#' + selectedMatchId);
    const title = a?.label || a?.title || '';
    const fromText = a ? `${a.date} • ${a.time} • ${a.field_code}` : '';

    box.hidden = false;
    box.innerHTML = `
      <div class="uk-text-small uk-text-bold">Выбран матч: ${esc(code)}</div>
      <div class="uk-text-small">${esc(title)}</div>
      <div class="uk-text-meta">Откуда: ${esc(fromText)}</div>
      <div class="uk-margin-small-top">
        <button type="button" class="uk-button uk-button-danger uk-button-small" id="btnCancelClickMove">Отмена</button>
      </div>
    `;

    const btn = document.getElementById('btnCancelClickMove');
    if (btn) btn.onclick = () => clearSelectedManualMatch();
  }

  function markSelectedManualMatch(mid){
    document.querySelectorAll('.match.is-selected-manual, .match-draggable.is-selected-manual')
      .forEach(el => el.classList.remove('is-selected-manual'));

    if (!mid) return;
    const el =
      document.querySelector(`.match[data-mid="${mid}"]`) ||
      document.querySelector(`.match-draggable[data-mid="${mid}"]`);
    if (el) el.classList.add('is-selected-manual');
  }

  function clearSelectedManualMatch(){
    selectedMatchId = 0;
    selectedFromCell = null;
    markSelectedManualMatch(0);
    renderSelectedMoveBar();
  }

  async function movePreview(move, assignmentsOverride=null){
"""
)

# 5) helper before bindUI
replace_once(
"""  function _dropCellFromEvent(e){
    // 1) пробуем по координатам (самое стабильное при оверлеях/карточках)
    const td0 = _dropCellFromPoint(e.clientX, e.clientY);
    if (td0) return td0;

    // 2) fallback: composedPath
    try{
      const path = (typeof e.composedPath === 'function') ? e.composedPath() : [];
      for (const el of path){
        if (el && el.nodeType === 1 && el.matches && el.matches('td[data-date][data-slot-index][data-field]')) return el;
      }
    }catch(_){/* ignore */}

    // 3) fallback: target.closest
    const td1 = e.target?.closest ? e.target.closest('td[data-date][data-slot-index][data-field]') : null;
    if (td1) return td1;
    return null;
  }

  function bindUI(){
""",
"""  function _dropCellFromEvent(e){
    // 1) пробуем по координатам (самое стабильное при оверлеях/карточках)
    const td0 = _dropCellFromPoint(e.clientX, e.clientY);
    if (td0) return td0;

    // 2) fallback: composedPath
    try{
      const path = (typeof e.composedPath === 'function') ? e.composedPath() : [];
      for (const el of path){
        if (el && el.nodeType === 1 && el.matches && el.matches('td[data-date][data-slot-index][data-field]')) return el;
      }
    }catch(_){/* ignore */}

    // 3) fallback: target.closest
    const td1 = e.target?.closest ? e.target.closest('td[data-date][data-slot-index][data-field]') : null;
    if (td1) return td1;
    return null;
  }

  async function applyManualMoveOrSwap(midDrag, from, td){
    if (!manualMode || !midDrag || !lastJson?.ok || !td) return;

    const to = {
      date: td.dataset.date,
      slot_index: Number(td.dataset.slotIndex || 0),
      slot_local: Number(td.dataset.slot || 0),
      slot_no: Number(td.dataset.slotIndex || 0),
      field_code: td.dataset.field,
      time: cellTimeForDrop(td),
      day_start: td.dataset.dayStart || '',
      day_end: td.dataset.dayEnd || ''
    };

    const srcA = findAssignmentByMatchId(lastJson, midDrag);
    if (!srcA){ notify('Не нашёл исходный assignment', 'warning'); return; }

    const safeFrom = from ? {
      date: String(from.date),
      slot_index: Number(from.slot_index || 0),
      slot_local: Number(from.slot_local || 0),
      slot_no: Number(from.slot_index || 0),
      field_code: String(from.field_code),
      time: normTime(String(from.time || '')),
      day_start: String(from.day_start || ''),
      day_end: String(from.day_end || '')
    } : {
      date: String(srcA.date),
      slot_index: Number(srcA.slot_index || srcA.slot_no || 0),
      slot_local: Number(srcA.slot_no || 0),
      slot_no: Number(srcA.slot_index || srcA.slot_no || 0),
      field_code: String(srcA.field_code),
      time: normTime(String(srcA.time || timeBySlot(lastJson, srcA.date, (srcA.slot_index || srcA.slot_no)) || '')),
      day_start: String(srcA.day_start || ''),
      day_end: String(srcA.day_end || '')
    };

    const targetMid = Number(td.dataset.mid || 0);

    if (!targetMid){
      const r = await movePreview({ match_id: midDrag, to });
      if (!r?.ok && hasReservedReason(r)) {
        showManualIssues('Слот зарезервирован', r, [midDrag]);
        return;
      }
      if (!r?.ok){
        showManualIssues('Перенос запрещён правилами', r, [midDrag]);
      } else {
        clearManualIssues();
      }
      applyMoveLocally(midDrag, to);
      render(lastJson);
      await applyConstraintHighlights();
      reapplyManualHighlights();
      highlightIssueSlotsFromReasons(r, to, safeFrom, midDrag, targetMid);
      if (selectedMatchId === midDrag) clearSelectedManualMatch();
      if (r?.ok) notify('Перенесено.', 'success');
      noticeOnce(makeNoticeSig(lastJson), () => summarizeIssues(lastJson));
      return;
    }

    if (targetMid === midDrag) return;
    if (!confirm(`Ячейка занята. Сделать SWAP?\\n${midDrag} ⇄ ${targetMid}`)) return;

    const assignNoTarget = cloneAssignmentsFilteredOut([targetMid]);
    const r1 = await movePreview({ match_id: midDrag, to }, assignNoTarget);

    const assignNoDrag = cloneAssignmentsFilteredOut([midDrag]);
    const r2 = await movePreview({ match_id: targetMid, to: safeFrom }, assignNoDrag);

    const merged = { ok: !!(r1?.ok && r2?.ok), reasons: [] };
    if (r1?.reasons) merged.reasons = merged.reasons.concat(r1.reasons);
    if (r2?.reasons) merged.reasons = merged.reasons.concat(r2.reasons);
    merged.error = r1?.error || r2?.error || '';

    if (hasReservedReason(merged)) {
      showManualIssues('Слот зарезервирован', merged);
      return;
    }

    if (!applySwapLocally(midDrag, targetMid, to, safeFrom)){
      notify('Локальный SWAP не применился (assignment не найден).', 'danger', 8000);
      return;
    }

    render(lastJson);
    await applyConstraintHighlights();
    await revalidateAllAssignments({ concurrency: 5, silent: true });
    reapplyManualHighlights();
    highlightIssueSlotsFromReasons(merged, to, safeFrom, midDrag, targetMid);

    if (!merged.ok){
      showManualIssues('SWAP с нарушениями', merged);
    } else {
      clearManualIssues();
      clearSlotHighlights();
    }
    if (selectedMatchId === midDrag) clearSelectedManualMatch();
    if (r1?.ok && r2?.ok) notify('SWAP выполнен.', 'success');
    noticeOnce(makeNoticeSig(lastJson), () => summarizeIssues(lastJson));
  }

  function bindUI(){
"""
)

# 6) insert click handler + manual toggle changes
replace_once(
"""    $('manual_mode')?.addEventListener('change', (e)=>{
      manualMode = !!e.target.checked;
      dragMatchId = 0;
      lastDropCell = null;
      clearDropMarks();
      if (lastJson?.ok) render(lastJson);
    });

    // source cell snapshot (critical: render/keys use date|time|field)
""",
"""    $('manual_mode')?.addEventListener('change', (e)=>{
      manualMode = !!e.target.checked;
      dragMatchId = 0;
      lastDropCell = null;
      if (!manualMode) clearSelectedManualMatch();
      clearDropMarks();
      if (lastJson?.ok) render(lastJson);
      if (manualMode && selectedMatchId) markSelectedManualMatch(selectedMatchId);
      renderSelectedMoveBar();
    });

    $('out')?.addEventListener('click', async (e)=>{
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

    // source cell snapshot (critical: render/keys use date|time|field)
"""
)

# 7) replace drop core
old_block = """    const td = lastDropCell || _dropCellFromEvent(e);
    if (!td) return;

    const to = {
      date: td.dataset.date,
      slot_index: Number(td.dataset.slotIndex || 0),
      slot_local: Number(td.dataset.slot || 0),
      slot_no: Number(td.dataset.slotIndex || 0), // for backend (current API)
      field_code: td.dataset.field,
      time: cellTimeForDrop(td),
      day_start: td.dataset.dayStart || '',
      day_end: td.dataset.dayEnd || ''
    };

    const srcA = findAssignmentByMatchId(lastJson, midDrag);
    if (!srcA){ notify('Не нашёл исходный assignment', 'warning'); return; }

    const from = fromCellSnap ? {
      date: String(fromCellSnap.date),
      slot_index: Number(fromCellSnap.slot_index || 0),
      slot_local: Number(fromCellSnap.slot_local || 0),
      slot_no: Number(fromCellSnap.slot_index || 0), // for backend (current API)
      field_code: String(fromCellSnap.field_code),
      time: normTime(String(fromCellSnap.time || '')),
      day_start: String(fromCellSnap.day_start || ''),
      day_end: String(fromCellSnap.day_end || '')
    } : {
      date: String(srcA.date),
      slot_index: Number(srcA.slot_index || srcA.slot_no || 0),
      slot_local: Number(srcA.slot_no || 0),
      slot_no: Number(srcA.slot_index || srcA.slot_no || 0), // for backend (current API)
      field_code: String(srcA.field_code),
      time: normTime(String(srcA.time || timeBySlot(lastJson, srcA.date, (srcA.slot_index || srcA.slot_no)) || '')),
      day_start: String(srcA.day_start || ''),
      day_end: String(srcA.day_end || '')
    };

    const targetMid = Number(td.dataset.mid || 0);

    // move
    if (!targetMid){
      const r = await movePreview({ match_id: midDrag, to });
      if (!r?.ok && hasReservedReason(r)) {
        showManualIssues('Слот зарезервирован', r, [midDrag]);
        return;
      }
      if (!r?.ok){
        showManualIssues('Перенос запрещён правилами', r, [midDrag]);
      } else {
        clearManualIssues();
      }
      applyMoveLocally(midDrag, to);
      render(lastJson);
      await applyConstraintHighlights();

      reapplyManualHighlights();

      highlightIssueSlotsFromReasons(r, to, from);

      if (r?.ok) notify('Перенесено.', 'success');
      noticeOnce(makeNoticeSig(lastJson), () => summarizeIssues(lastJson));
      return;
    }

     // swap
     if (targetMid === midDrag) return;

     if (!confirm(`Ячейка занята. Сделать SWAP?\\n${midDrag} ⇄ ${targetMid}`)) return;

     const assignNoTarget = cloneAssignmentsFilteredOut([targetMid]);
     const r1 = await movePreview({ match_id: midDrag, to }, assignNoTarget);

     const assignNoDrag = cloneAssignmentsFilteredOut([midDrag]);
     const r2 = await movePreview({ match_id: targetMid, to: from }, assignNoDrag);

     // соберём merged сразу (и для алерта, и для подсветки)
     const merged = { ok: !!(r1?.ok && r2?.ok), reasons: [] };
     if (r1?.reasons) merged.reasons = merged.reasons.concat(r1.reasons);
     if (r2?.reasons) merged.reasons = merged.reasons.concat(r2.reasons);
     merged.error = r1?.error || r2?.error || '';

     if (hasReservedReason(merged)) {
       showManualIssues('Слот зарезервирован', merged);
       return;
     }

     // ✅ apply with fixed ids
     if (!applySwapLocally(midDrag, targetMid, to, from)){
       notify('Локальный SWAP не применился (assignment не найден).', 'danger', 8000);
       return;
     }

     render(lastJson);
     await applyConstraintHighlights();
     await revalidateAllAssignments({ concurrency: 5, silent: true });

     reapplyManualHighlights();


     // ✅ ВОТ СЮДА: после перерисовки
     //highlightIssueSlotsFromReasons(merged, to, from);
     highlightIssueSlotsFromReasons(merged, to, from, midDrag, targetMid);

     // алерт/очистка — после, чтобы не сбить подсветку
     if (!merged.ok){
       showManualIssues('SWAP с нарушениями', merged);
     } else {
       clearManualIssues(); // он НЕ должен чистить td-подсветку, только текст/матчи
       clearSlotHighlights();
     }

        if (r1?.ok && r2?.ok) notify('SWAP выполнен.', 'success');
        noticeOnce(makeNoticeSig(lastJson), () => summarizeIssues(lastJson));"""
new_block = """    const td = lastDropCell || _dropCellFromEvent(e);
    if (!td) return;
    await applyManualMoveOrSwap(midDrag, fromCellSnap, td);"""
replace_once(old_block, new_block)

backup = p.with_suffix(p.suffix + ".pre_click_move.bak")
backup.write_text(p.read_text(encoding="utf-8"), encoding="utf-8")
p.write_text(text, encoding="utf-8")
print(f"OK: {p}")
print(f"Backup: {backup}")
