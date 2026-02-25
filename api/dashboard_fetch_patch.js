/**
 * DASHBOARD FETCH PATCH — drop this into your dashboard.js
 *
 * The "Analysis Failed — Non-JSON:" error happens because:
 *   1. Groq rate-limits or errors → returns 429/400 HTTP code
 *   2. The PHP gets a non-JSON Groq response during analysis
 *   3. The fetch() call does res.json() which throws a SyntaxError
 *   4. Your catch block shows "Non-JSON: <raw response text>"
 *
 * REPLACE your existing analyseAndStore() / runAnalysis() / fetch call
 * with this safe version. It uses res.text() first, then tries JSON.parse(),
 * and shows a real human-readable error if it fails.
 */

/**
 * Safe fetch wrapper — never crashes on non-JSON responses.
 * Returns { ok: true, data: {...} } or { ok: false, error: "...", raw: "..." }
 */
async function safeFetch(url, options = {}) {
  let res;
  try {
    res = await fetch(url, options);
  } catch (networkErr) {
    return { ok: false, error: `Network error: ${networkErr.message}`, raw: "" };
  }

  const text = await res.text();

  // Try to parse as JSON
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    // Not JSON — figure out what went wrong
    let hint = "";
    if (res.status === 429) hint = "Rate limit hit. Please wait 30 seconds and try again.";
    else if (res.status === 401) hint = "API key invalid. Check your GROQ_KEY in config.php.";
    else if (res.status === 504 || res.status === 502) hint = "Server timeout. The analysis took too long — try again.";
    else if (res.status === 500) hint = "Server error. Check PHP error logs.";
    else if (text.includes("Fatal error") || text.includes("Parse error")) hint = "PHP error: " + text.substring(0, 200);
    else if (text.includes("<!DOCTYPE") || text.includes("<html")) hint = "Server returned an HTML page instead of JSON. Check PHP logs.";
    else hint = text.substring(0, 300);

    return { ok: false, error: hint, raw: text, status: res.status };
  }

  if (data && data.success === false) {
    return { ok: false, error: data.error || "Analysis returned an error", data };
  }

  return { ok: true, data };
}

/**
 * HOW TO USE — find your existing analysis fetch and replace it.
 *
 * BEFORE (the broken pattern):
 *   const res = await fetch('seller_research.php', { method: 'POST', ... });
 *   const data = await res.json();   ← THIS CRASHES on non-JSON
 *   if (!data.success) throw new Error(data.error);
 *
 * AFTER (the safe pattern):
 *   const result = await safeFetch('seller_research.php', { method: 'POST', ... });
 *   if (!result.ok) {
 *     showError(result.error);
 *     return;
 *   }
 *   const data = result.data;
 *   // continue with data...
 */

/**
 * EXAMPLE — full analysis function using the safe pattern.
 * Replace your existing runAnalysis() / analyseAndStore() with this.
 */
async function runAnalysis(sellerId, forceRefresh = false) {
  // Show loading state
  const btn = document.querySelector('[data-analyse-btn]') || document.querySelector('.analyse-btn');
  const errorEl = document.getElementById('analysisError') || document.getElementById('errorMsg');
  const loadingEl = document.getElementById('loadingState') || document.querySelector('.loading');

  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Analysing…';
  }
  if (errorEl) errorEl.style.display = 'none';
  if (loadingEl) loadingEl.style.display = 'block';

  const result = await safeFetch('seller_research.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ seller_id: sellerId, force_refresh: forceRefresh }),
  });

  if (btn) {
    btn.disabled = false;
    btn.textContent = 'Analyse';
  }
  if (loadingEl) loadingEl.style.display = 'none';

  if (!result.ok) {
    // Show the real error message — not "Non-JSON:"
    const msg = result.error || 'Analysis failed. Please try again.';
    if (errorEl) {
      errorEl.textContent = msg;
      errorEl.style.display = 'block';
    } else {
      alert('Analysis failed: ' + msg);
    }
    console.error('Analysis failed:', result);
    return null;
  }

  const data = result.data;

  // Save to localStorage for keywords/competitors pages
  localStorage.setItem('lastReport', JSON.stringify(data));

  // Also save seller details if present
  if (data.seller) {
    localStorage.setItem('sellerDetails', JSON.stringify({
      products_offered: data.seller.products,
      city: data.seller.city,
      state: data.seller.state,
    }));
  }

  console.log('Analysis complete:', {
    model_used: data.debug?.strategy_model_used,
    keywords: data.strategy?.keyword_targets?.length,
    seo_fixes: data.strategy?.seo_fixes?.length,
    competitors: data.competitor_count,
    groq_error: data.debug?.strategy_groq_error || 'none',
  });

  return data;
}