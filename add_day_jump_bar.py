from pathlib import Path
import sys

root = Path('.')
target = root / 'admin' / 'organizer' / 'schedule.html'
if not target.exists():
    print(f'ERROR: file not found: {target}')
    sys.exit(1)

text = target.read_text(encoding='utf-8')

changes = 0

# 1) Add jump bar container above #out
old = """    <div class="lc-schedule-lab">
      <div class="uk-card uk-card-default uk-card-body uk-box-shadow-small uk-margin">
"""
new = """    <div class="lc-schedule-lab">
      <div id="dayJumpBar" class="uk-margin-small-bottom"></div>
      <div class="uk-card uk-card-default uk-card-body uk-box-shadow-small uk-margin">
"""
if old in text and 'id="dayJumpBar"' not in text:
    text = text.replace(old, new, 1)
    changes += 1

# 2) Add renderDayJumpBar() after renderSelectedMoveBar()
anchor = """  function renderSelectedMoveBar() {
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
        <div class="uk-text-meta uk-margin-small-bottom">Клик по пустой ячейке — перенос, по занятой — swap</div>
        <button type="button" class="uk-button uk-button-danger uk-button-small" id="btnCancelClickMove">Отмена</button>
      </div>
    `;

    const btn = document.getElementById('btnCancelClickMove');
    if (btn) btn.onclick = () => clearSelectedManualMatch();
  }
"""
insert = anchor + """
  function renderDayJumpBar(days) {
    const box = $('dayJumpBar');
    if (!box) return;

    const dates = [...new Set((days || []).map(d => String(d?.date || '')).filter(Boolean))];
    if (!dates.length) {
      box.innerHTML = '';
      return;
    }

    box.innerHTML = `
      <div class="uk-flex uk-flex-wrap uk-grid-small" uk-grid>
        ${dates.map(d => `
          <div>
            <button type="button" class="uk-button uk-button-default uk-button-small" data-day-jump="${esc(d)}">${esc(d)}</button>
          </div>
        `).join('')}
      </div>
    `;

    box.querySelectorAll('[data-day-jump]').forEach(btn => {
      btn.onclick = () => {
        const date = String(btn.dataset.dayJump || '');
        const anchor = document.getElementById(`day-${date}`);
        if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
      };
    });

    try { if (window.UIkit) UIkit.update(box); } catch(e) {}
  }
"""
if anchor in text and 'function renderDayJumpBar(days)' not in text:
    text = text.replace(anchor, insert, 1)
    changes += 1

# Fallback if the helper has old version without hint text
anchor_old = """  function renderSelectedMoveBar() {
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
"""
if anchor_old in text and 'function renderDayJumpBar(days)' not in text:
    text = text.replace(anchor_old, insert, 1)
    changes += 1

# 3) Add anchor set in render()
old = """  function render(j){
    const out = $('out');
    out.innerHTML = '';
"""
new = """  function render(j){
    const out = $('out');
    out.innerHTML = '';
    const renderedDayAnchors = new Set();
"""
if old in text and 'const renderedDayAnchors = new Set();' not in text:
    text = text.replace(old, new, 1)
    changes += 1

# 4) Assign id to first card of each date
old = """    for (const d of (j.days || [])){
      const card = document.createElement('div');
      card.className = 'uk-card uk-card-default uk-card-body uk-margin';
"""
new = """    for (const d of (j.days || [])){
      const card = document.createElement('div');
      card.className = 'uk-card uk-card-default uk-card-body uk-margin';
      const dayAnchorId = `day-${String(d.date || '')}`;
      if (String(d.date || '') && !renderedDayAnchors.has(dayAnchorId)) {
        card.id = dayAnchorId;
        renderedDayAnchors.add(dayAnchorId);
      }
"""
if old in text and 'const dayAnchorId = `day-${String(d.date || \'\')}`;' not in text:
    text = text.replace(old, new, 1)
    changes += 1

# 5) Render jump bar on success path
old = """      //card.appendChild(un);
      out.appendChild(card);
    }
  }
"""
new = """      //card.appendChild(un);
      out.appendChild(card);
    }

    renderDayJumpBar(j.days || []);
  }
"""
if old in text and 'renderDayJumpBar(j.days || []);' not in text:
    text = text.replace(old, new, 1)
    changes += 1

backup = target.with_suffix(target.suffix + '.pre_day_jump_fix.bak')
backup.write_text(target.read_text(encoding='utf-8'), encoding='utf-8')
target.write_text(text, encoding='utf-8')

print(f'Changes applied: {changes}')
print(f'Backup: {backup}')
print(f'Updated: {target}')
if changes == 0:
    print('WARNING: no changes were applied. The file may already be updated or its content differs from the expected state.')
