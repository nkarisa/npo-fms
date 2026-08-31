(async function () {
  window.__journalDetailStarted = true;
  window.__journalDetailLogs = window.__journalDetailLogs || [];
  
  const log = (msg) => {
    console.log('[journal_detail]', msg);
    window.__journalDetailLogs.push(msg);
  };
  
  log('IIFE starting');
  
  const app = document.getElementById('app');
  log('app element: ' + (app ? 'found' : 'NOT FOUND'));
  
  if (!app) {
    window.__journalDetailError = 'app element not found!';
    return;
  }
  
  const ref = app.getAttribute('data-ref');
  log('ref: ' + ref);

  async function load() {
    log('loading journal: ' + ref);
    try {
      const journal = await UI.fetchJSON(`/api/journals/${ref}`);
      log('loaded journal: ' + journal.ref);
      render(journal);
      log('render complete');
      return journal;
    } catch (err) {
      window.__journalDetailError = err.message;
      log('error in load: ' + err.message);
      UI.toast('Error loading journal: ' + err.message, 'error');
    }
  }

  function formatAmount(n) {
    if (n === 0) return '—';
    const s = Math.abs(n).toLocaleString('en-US');
    return n < 0 ? `(${s})` : s;
  }

  function render(journal) {
    log('render starting with: ' + journal.ref);
    app.innerHTML = '';
    log('cleared app');
    
    const sumDr = journal.lines.reduce((sum, l) => sum + (l.dr || 0), 0);
    const sumCr = journal.lines.reduce((sum, l) => sum + (l.cr || 0), 0);
    log('sums: dr=' + sumDr + ' cr=' + sumCr);
    
    try {
      // Page header with navigation
      UI.pageHead(app, {
        kicker: 'Accounting',
        title: `Journal ${journal.ref}`,
        blurb: journal.narration || 'No narration provided',
      });
      log('pageHead added');

      // Main content area
      const container = document.createElement('div');
      container.style.cssText = 'display:grid;grid-template-columns:1fr 320px;gap:16px;';
    
      // Left column: Entry details and lines
      const main = document.createElement('div');
      main.style.cssText = 'display:flex;flex-direction:column;gap:16px;';

      // Summary card
      const summaryCard = document.createElement('div');
      summaryCard.className = 'card';
      summaryCard.style.cssText = 'padding:18px 22px;';
      summaryCard.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:20px;">
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Reference</div>
            <div style="font-size:14px;font-weight:600;">${UI.esc(journal.ref)}</div>
          </div>
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Date</div>
            <div style="font-size:14px;font-weight:600;">${UI.esc(journal.date)}</div>
          </div>
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Type</div>
            <div style="font-size:14px;font-weight:600;">${UI.esc(journal.type)}</div>
          </div>
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Status</div>
            <div>${UI.badge(journal.status, journal.status === 'Posted' ? 'calm' : journal.status === 'Draft' ? 'plain' : journal.status === 'Reversed' ? 'urgent' : 'warn')}</div>
          </div>
        </div>
      `;
      main.appendChild(summaryCard);

      // Entry details card
      const detailsCard = document.createElement('div');
      detailsCard.className = 'card';
      detailsCard.style.cssText = 'padding:18px 22px;';
      let detailsHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px 40px;font-size:13px;line-height:1.6;">
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Preparer</div>
            <div>${UI.esc(journal.preparer)}</div>
          </div>
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Period</div>
            <div>${UI.esc(journal.period)}</div>
          </div>
          ${journal.doc ? `
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Supporting document</div>
            <div>${UI.esc(journal.doc)}</div>
          </div>
          ` : ''}
          ${journal.memo ? `
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Memo</div>
            <div style="white-space:pre-wrap;">${UI.esc(journal.memo)}</div>
          </div>
          ` : ''}
        </div>
      `;
      
      // Add reversal link if this is a reversing entry
      if (journal.reversalOf) {
        detailsHTML += `
        <div style="padding:18px 22px;border-top:1px solid #EEEDE8;">
          <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:4px;">Reverses entry</div>
          <div><a href="/journals/${UI.esc(journal.reversalOf)}" style="color:#0D5B5B;text-decoration:none;font-weight:500;">${UI.esc(journal.reversalOf)}</a></div>
        </div>`;
      }
      
      detailsCard.innerHTML = detailsHTML;
      main.appendChild(detailsCard);

      // Lines table card
      const linesCard = document.createElement('div');
      linesCard.className = 'card';
      const linesTitle = document.createElement('div');
      linesTitle.style.cssText = 'padding:18px 22px;border-bottom:1px solid #EEEDE8;font-size:12px;letter-spacing:0.09em;text-transform:uppercase;color:#6E7873;font-weight:600;';
      linesTitle.textContent = 'Journal lines';
      linesCard.appendChild(linesTitle);

      const linesTable = document.createElement('table');
      linesTable.className = 'data';
      linesTable.style.cssText = 'width:100%;';
      linesTable.innerHTML = `
        <thead>
          <tr>
            <th>Account</th>
            <th>Description</th>
            <th>Fund</th>
            <th>Programme</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
          </tr>
        </thead>
        <tbody>
          ${journal.lines.map(l => `
            <tr>
              <td style="font-weight:600;">${UI.esc(l.code || '—')}</td>
              <td>${UI.esc(l.desc || '—')}</td>
              <td>${UI.esc(l.fund || '—')}</td>
              <td>${UI.esc(l.program || '—')}</td>
              <td class="num">${l.dr ? formatAmount(l.dr) : '—'}</td>
              <td class="num">${l.cr ? formatAmount(l.cr) : '—'}</td>
            </tr>
          `).join('')}
          <tr style="border-top:2px solid #DDDAD2;font-weight:600;">
            <td colspan="4" style="text-align:right;padding-right:12px;">Total:</td>
            <td class="num">${formatAmount(sumDr)}</td>
            <td class="num">${formatAmount(sumCr)}</td>
          </tr>
        </tbody>
      `;
      linesCard.appendChild(linesTable);
      main.appendChild(linesCard);

      // Audit trail card
      if (journal.trail && journal.trail.length > 0) {
        const trailCard = document.createElement('div');
        trailCard.className = 'card';
        const trailTitle = document.createElement('div');
        trailTitle.style.cssText = 'padding:18px 22px;border-bottom:1px solid #EEEDE8;font-size:12px;letter-spacing:0.09em;text-transform:uppercase;color:#6E7873;font-weight:600;';
        trailTitle.textContent = 'Audit trail';
        trailCard.appendChild(trailTitle);

        const trailList = document.createElement('div');
        trailList.style.cssText = 'padding:18px 22px;';
        trailList.innerHTML = journal.trail.map((t, i) => `
          <div style="display:flex;gap:12px;padding-bottom:${i < journal.trail.length - 1 ? '12px;border-bottom:1px solid #EEEDE8;' : '0;'}">
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;min-width:60px;">${UI.esc(t.when)}</div>
            <div style="font-size:12px;color:#332C2A;">${UI.esc(t.what)}</div>
          </div>
        `).join('');
        trailCard.appendChild(trailList);
        main.appendChild(trailCard);
      }

      // Right sidebar: Quick info and actions
      const sidebar = document.createElement('div');
      sidebar.style.cssText = 'display:flex;flex-direction:column;gap:16px;';

      // Status card
      const statusCard = document.createElement('div');
      statusCard.className = 'card';
      statusCard.style.cssText = 'padding:18px 22px;';
      statusCard.innerHTML = `
        <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;margin-bottom:8px;">Status</div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
          ${UI.badge(journal.status, journal.status === 'Posted' ? 'calm' : journal.status === 'Draft' ? 'plain' : journal.status === 'Reversed' ? 'urgent' : 'warn')}
          <span style="font-size:12px;color:#6E7873;">${
            journal.status === 'Draft' ? 'Ready to submit' :
            journal.status === 'Pending approval' ? 'Awaiting approval' :
            journal.status === 'Posted' ? 'Posted to ledger' :
            'Entry reversed'
          }</span>
        </div>
        <div style="font-size:12px;line-height:1.6;color:#6E7873;">
          ${
            journal.status === 'Draft' ? 'This entry is saved but has not been submitted for approval.' :
            journal.status === 'Pending approval' ? 'Waiting for W. Kamau to approve this entry.' :
            journal.status === 'Posted' ? 'This entry has been posted to the general ledger.' :
            'This entry has been reversed with a memo entry.'
          }
        </div>
      `;
      sidebar.appendChild(statusCard);

      // Actions card
      const actionsCard = document.createElement('div');
      actionsCard.className = 'card';
      actionsCard.style.cssText = 'padding:18px 22px;display:flex;flex-direction:column;gap:8px;';
      
      if (journal.status === 'Draft') {
        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-primary';
        editBtn.style.cssText = 'width:100%;';
        editBtn.textContent = 'Edit entry';
        editBtn.addEventListener('click', () => {
          UI.toast('Edit functionality coming soon');
        });
        actionsCard.appendChild(editBtn);

        const submitBtn = document.createElement('button');
        submitBtn.className = 'btn';
        submitBtn.style.cssText = 'width:100%;';
        submitBtn.textContent = 'Submit for approval';
        submitBtn.addEventListener('click', () => {
          UI.toast('Submit functionality coming soon');
        });
        actionsCard.appendChild(submitBtn);
      } else if (journal.status === 'Pending approval') {
        const approveBtn = document.createElement('button');
        approveBtn.className = 'btn btn-primary';
        approveBtn.style.cssText = 'width:100%;';
        approveBtn.textContent = 'Approve & post';
        approveBtn.addEventListener('click', async () => {
          approveBtn.disabled = true;
          approveBtn.textContent = 'Processing...';
          try {
            const response = await fetch(`/api/journals/${ref}/approve`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
            });
            if (!response.ok) {
              const error = await response.json();
              UI.toast(error.error || 'Failed to approve entry', 'error');
              approveBtn.disabled = false;
              approveBtn.textContent = 'Approve & post';
              return;
            }
            UI.toast('Entry approved and posted successfully');
            setTimeout(() => window.location.href = '/journals', 800);
          } catch (err) {
            UI.toast('Error approving entry: ' + err.message, 'error');
            approveBtn.disabled = false;
            approveBtn.textContent = 'Approve & post';
          }
        });
        actionsCard.appendChild(approveBtn);

        const rejectBtn = document.createElement('button');
        rejectBtn.className = 'btn';
        rejectBtn.style.cssText = 'width:100%;';
        rejectBtn.textContent = 'Reject';
        rejectBtn.addEventListener('click', async () => {
          // Show rejection reason dialog
          const dialogBackdrop = document.createElement('div');
          dialogBackdrop.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
          
          const dialogCard = document.createElement('div');
          dialogCard.style.cssText = 'background:white;border-radius:8px;padding:24px;max-width:400px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.2);';
          
          dialogCard.innerHTML = `
            <div style="font-size:16px;font-weight:600;margin-bottom:12px;">Reject Entry</div>
            <div style="font-size:13px;color:#6E7873;margin-bottom:20px;">Provide a reason for rejecting this journal entry. It will be returned to draft status.</div>
            <textarea id="rejection-reason" placeholder="Optional reason for rejection..." style="width:100%;padding:8px 12px;border:1px solid #D3CCC1;border-radius:4px;font-family:inherit;font-size:13px;resize:vertical;min-height:80px;"></textarea>
            <div style="display:flex;gap:8px;margin-top:16px;">
              <button id="reject-cancel" class="btn" style="flex:1;">Cancel</button>
              <button id="reject-confirm" class="btn btn-primary" style="flex:1;">Reject</button>
            </div>
          `;
          
          dialogBackdrop.appendChild(dialogCard);
          document.body.appendChild(dialogBackdrop);
          
          const reasonInput = document.getElementById('rejection-reason');
          reasonInput.focus();
          
          document.getElementById('reject-cancel').addEventListener('click', () => {
            dialogBackdrop.remove();
          });
          
          document.getElementById('reject-confirm').addEventListener('click', async () => {
            const reason = reasonInput.value.trim();
            document.getElementById('reject-confirm').disabled = true;
            document.getElementById('reject-confirm').textContent = 'Processing...';
            
            try {
              const response = await fetch(`/api/journals/${ref}/reject`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason }),
              });
              if (!response.ok) {
                const error = await response.json();
                UI.toast(error.error || 'Failed to reject entry', 'error');
                document.getElementById('reject-confirm').disabled = false;
                document.getElementById('reject-confirm').textContent = 'Reject';
                return;
              }
              UI.toast('Entry rejected and returned to draft');
              dialogBackdrop.remove();
              setTimeout(() => window.location.reload(), 800);
            } catch (err) {
              UI.toast('Error rejecting entry: ' + err.message, 'error');
              document.getElementById('reject-confirm').disabled = false;
              document.getElementById('reject-confirm').textContent = 'Reject';
            }
          });
        });
        actionsCard.appendChild(rejectBtn);
      } else if (journal.status === 'Posted') {
        const reverseBtn = document.createElement('button');
        reverseBtn.className = 'btn';
        reverseBtn.style.cssText = 'width:100%;';
        reverseBtn.textContent = 'Create reversal';
        reverseBtn.addEventListener('click', async () => {
          // Create a reversal entry with flipped amounts
          const reversalLines = journal.lines.map(l => ({
            code: l.code,
            desc: l.desc,
            fund: l.fund,
            program: l.program,
            dr: l.cr || 0,  // Flip: credit becomes debit
            cr: l.dr || 0,  // Flip: debit becomes credit
          }));

          const reversalNarration = `Reversal of ${UI.esc(ref)} — ${UI.esc(journal.narration)}`;

          const reversalData = {
            type: 'Reversing',
            date: new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }),
            period: journal.period,
            preparer: journal.preparer,
            doc: journal.doc,
            memo: 'Automatic reversal',
            narration: reversalNarration,
            reversalOf: ref,
            lines: reversalLines,
            status: 'Draft',
          };

          reverseBtn.disabled = true;
          reverseBtn.textContent = 'Creating...';

          try {
            const response = await fetch('/api/journals', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(reversalData),
            });

            if (!response.ok) {
              const error = await response.json();
              UI.toast(error.error || 'Failed to create reversal', 'error');
              reverseBtn.disabled = false;
              reverseBtn.textContent = 'Create reversal';
              return;
            }

            const result = await response.json();
            UI.toast(`Reversal entry ${result.journal.ref} created successfully`);
            setTimeout(() => window.location.href = `/journals/${result.journal.ref}`, 800);
          } catch (err) {
            UI.toast('Error creating reversal: ' + err.message, 'error');
            reverseBtn.disabled = false;
            reverseBtn.textContent = 'Create reversal';
          }
        });
        actionsCard.appendChild(reverseBtn);
      }

      const backBtn = document.createElement('button');
      backBtn.className = 'btn';
      backBtn.style.cssText = 'width:100%;';
      backBtn.textContent = 'Back to list';
      backBtn.addEventListener('click', () => {
        window.location.href = '/journals';
      });
      actionsCard.appendChild(backBtn);

      sidebar.appendChild(actionsCard);

      container.appendChild(main);
      container.appendChild(sidebar);
      app.appendChild(container);
      
      log('render complete');
    } catch (err) {
      log('render error: ' + err.message);
      window.__journalDetailError = err.message + '\n' + err.stack;
      app.innerHTML = `
        <div class="card" style="padding:40px;text-align:center;">
          <div style="font-size:16px;font-weight:600;color:#A5442F;margin-bottom:12px;">Error rendering page</div>
          <div style="font-size:13px;color:#6E7873;margin-bottom:20px;">${UI.esc(err.message)}</div>
          <button class="btn" onclick="window.location.href='/journals'">Back to journals</button>
        </div>
      `;
    }
  }

  async function refresh() {
    try {
      const data = await load();
      render(data);
    } catch (err) {
      app.innerHTML = `
        <div class="card" style="padding:40px;text-align:center;">
          <div style="font-size:16px;font-weight:600;color:#A5442F;margin-bottom:12px;">Entry not found</div>
          <div style="font-size:13px;color:#6E7873;margin-bottom:20px;">The journal entry ${UI.esc(ref)} could not be found.</div>
          <button class="btn" onclick="window.location.href='/journals'">Back to journals</button>
        </div>
      `;
    }
  }

  refresh();
})();
