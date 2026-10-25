<div data-agreement-choice style="margin:12px 0">
  <div data-agreement-controls hidden>
    <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
      <label class="field"><span class="label">Agreement</span>
        <select class="input" data-agreement-kind>
          <option value="one_time">One-time work</option>
          <option value="ongoing">Ongoing service agreement</option>
        </select>
      </label>
      <label class="field" data-agreement-billing-field hidden><span class="label">Invoicing</span>
        <select class="input" data-agreement-billing>
          <option value="long_term">Recurring schedule</option>
          <option value="on_demand">As needed</option>
        </select>
      </label>
    </div>
    <small>Use an ongoing service agreement for recurring services or work invoiced as needed.</small>
  </div>
  <fieldset data-agreement-fallback style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
    <legend>Agreement and invoicing</legend>
    <label><input type="radio" name="doc_type" value="regular" checked onchange="toggleDocTypeFields()"> One-time work</label>
    <label><input type="radio" name="doc_type" value="long_term" onchange="toggleDocTypeFields()"> Ongoing agreement — recurring invoices</label>
    <label><input type="radio" name="doc_type" value="on_demand" onchange="toggleDocTypeFields()"> Ongoing agreement — invoices as needed</label>
  </fieldset>
</div>
<noscript><style>#longTermFields,#onDemandFields,#onDemandFieldsCo{display:block!important}</style><p>Complete the settings for the agreement and invoicing option selected above.</p></noscript>
