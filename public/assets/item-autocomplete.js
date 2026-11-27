// public/assets/item-autocomplete.js
// Reusable item autocomplete component

class ItemAutocomplete {
  constructor(inputElement, options = {}) {
    this.input = inputElement;
    this.descriptionField = options.descriptionField || null;
    this.priceField = options.priceField || null;
    this.onSelect = options.onSelect || null;
    this.libraryIdField = options.libraryIdField || null;
    
    this.dropdown = null;
    this.debounceTimer = null;
    this.selectedIndex = -1;
    this.items = [];
    
    this.init();
  }
  
  init() {
    this.ensureLibraryIdField();
    // Create dropdown element
    this.dropdown = document.createElement('div');
    this.dropdown.className = 'item-autocomplete-dropdown';
    this.dropdown.style.cssText = `
      position: absolute;
      background: white;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      max-height: 300px;
      overflow-y: auto;
      display: none;
      z-index: 1000;
      min-width: ${this.input.closest('.invoice-item-editor__row') ? '0' : '300px'};
      top: calc(100% + 4px);
      left: 0;
      right: 0;
    `;
    
    // Position dropdown relative to input
    this.input.style.position = 'relative';
    this.input.parentElement.style.position = 'relative';
    this.input.parentElement.appendChild(this.dropdown);
    
    // Bind events
    this.input.addEventListener('input', (e) => {
      if (this.libraryIdField) this.libraryIdField.value = '';
      this.handleInput(e);
    });
    this.input.addEventListener('keydown', (e) => this.handleKeydown(e));
    this.input.addEventListener('blur', () => {
      // Delay to allow click on dropdown
      setTimeout(() => this.hideDropdown(), 200);
    });
    
  }

  ensureLibraryIdField() {
    if (this.libraryIdField) return;
    const explicitId = this.input.dataset.libraryIdField;
    if (explicitId) {
      this.libraryIdField = document.getElementById(explicitId);
      if (this.libraryIdField) return;
    }

    const inputName = this.input.getAttribute('name') || '';
    const hiddenName = inputName === 'item[]'
      ? 'item_library_id[]'
      : (inputName === 'extra_item[]' ? 'extra_item_library_id[]' : '');
    if (!hiddenName) return;

    const sibling = this.input.parentElement?.querySelector(`input[type="hidden"][name="${hiddenName}"]`);
    if (sibling) {
      this.libraryIdField = sibling;
      return;
    }
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = hiddenName;
    hidden.value = this.input.dataset.itemLibraryId || '';
    this.input.insertAdjacentElement('afterend', hidden);
    this.libraryIdField = hidden;
  }
  
  handleInput(e) {
    const query = e.target.value.trim();
    
    // Clear previous timer
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
    
    // Hide if empty
    if (query.length < 1) {
      this.hideDropdown();
      return;
    }
    
    // Debounce search
    this.debounceTimer = setTimeout(() => {
      this.search(query);
    }, 300);
  }
  
  async search(query) {
    try {
      const response = await fetch(`/?page=settings/item-library-search&q=${encodeURIComponent(query)}`);
      const data = await response.json();
      
      this.items = data;
      this.selectedIndex = -1;
      
      if (data.length > 0) {
        this.showDropdown(data);
      } else {
        this.hideDropdown();
      }
    } catch (error) {
      console.error('Autocomplete search failed:', error);
    }
  }
  
  showDropdown(items) {
    this.dropdown.innerHTML = '';
    
    items.forEach((item, index) => {
      const div = document.createElement('div');
      div.className = 'item-autocomplete-item';
      div.style.cssText = `
        padding: 10px 12px;
        cursor: pointer;
        border-bottom: 1px solid #f3f4f6;
        transition: background 0.15s;
      `;
      
      div.innerHTML = `
        <div style="font-weight: 500; color: #111827;">${this.escapeHtml(item.item_name)}</div>
        ${item.description ? `<div style="font-size: 12px; color: #6b7280; margin-top: 2px;">${this.escapeHtml(item.description)}</div>` : ''}
        <div style="font-size: 12px; color: #059669; margin-top: 4px; font-weight: 600;">$${parseFloat(item.unit_price).toFixed(2)}</div>
      `;
      
      div.addEventListener('mouseenter', () => {
        this.selectedIndex = index;
        this.updateSelection();
      });
      
      div.addEventListener('click', () => {
        this.selectItem(item);
      });
      
      this.dropdown.appendChild(div);
    });
    
    this.dropdown.style.display = 'block';
    this.updateSelection();
  }
  
  hideDropdown() {
    this.dropdown.style.display = 'none';
    this.selectedIndex = -1;
  }
  
  handleKeydown(e) {
    if (!this.dropdown.style.display || this.dropdown.style.display === 'none') {
      return;
    }
    
    switch(e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.selectedIndex = Math.min(this.selectedIndex + 1, this.items.length - 1);
        this.updateSelection();
        break;
        
      case 'ArrowUp':
        e.preventDefault();
        this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
        this.updateSelection();
        break;
        
      case 'Enter':
        e.preventDefault();
        if (this.selectedIndex >= 0 && this.selectedIndex < this.items.length) {
          this.selectItem(this.items[this.selectedIndex]);
        }
        break;
        
      case 'Escape':
        this.hideDropdown();
        break;
    }
  }
  
  updateSelection() {
    const itemDivs = this.dropdown.querySelectorAll('.item-autocomplete-item');
    itemDivs.forEach((div, index) => {
      if (index === this.selectedIndex) {
        div.style.background = '#f3f4f6';
      } else {
        div.style.background = 'white';
      }
    });
    
    // Scroll selected into view
    if (this.selectedIndex >= 0) {
      itemDivs[this.selectedIndex]?.scrollIntoView({ block: 'nearest' });
    }
  }
  
  selectItem(item) {
    // Set the item name in the input
    this.input.value = item.item_name;
    if (this.libraryIdField) {
      this.libraryIdField.value = String(item.id || '');
    }
    
    // Auto-fill description if field is provided
    if (this.descriptionField && item.description) {
      this.descriptionField.value = item.description;
    }
    
    // Auto-fill price if field is provided
    if (this.priceField) {
      this.priceField.value = parseFloat(item.unit_price).toFixed(2);
      this.priceField.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // When an hourly item is selected, mark the row as hour-based.
    const row = this.input.closest('.invoice-item-editor__row')
      || this.input.closest('[style*="grid"]')
      || this.input.parentElement;
    if (row) {
      const qtyInput = row.querySelector('.qty-input') || row.querySelector('[name="item_qty[]"]');
      const unitInput = row.querySelector('[name="item_billing_unit[]"]');
      const billingUnit = item.billing_unit || (item.is_hourly ? 'hour' : 'each');
      if (billingUnit === 'hour') {
        if (qtyInput) {
          qtyInput.placeholder = 'Hours';
        }
        if (unitInput) {
          unitInput.value = billingUnit;
        }
      } else {
        if (qtyInput) {
          qtyInput.placeholder = 'Qty';
        }
        if (unitInput) {
          unitInput.value = billingUnit;
        }
      }
    }
    
    // Call custom callback if provided
    if (this.onSelect) {
      this.onSelect(item);
    }
    
    this.hideDropdown();
    
    // Focus next field (usually quantity or description)
    const nextField = this.descriptionField || this.input.nextElementSibling;
    if (nextField && (nextField.tagName === 'INPUT' || nextField.tagName === 'TEXTAREA')) {
      nextField.focus();
    }
  }
  
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  destroy() {
    if (this.dropdown && this.dropdown.parentElement) {
      this.dropdown.parentElement.removeChild(this.dropdown);
    }
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
  }
}

// Expose globally for manual initialization
window.ItemAutocomplete = ItemAutocomplete;

// Initialize autocomplete for all item inputs on page
function initItemAutocomplete() {
  document.querySelectorAll('[data-item-autocomplete]').forEach(input => {
    // Skip if already initialized
    if (input._itemAutocomplete) return;
    
    const descriptionFieldId = input.dataset.descriptionField;
    const priceFieldId = input.dataset.priceField;
    
    const instance = new ItemAutocomplete(input, {
      descriptionField: descriptionFieldId ? document.getElementById(descriptionFieldId) : null,
      priceField: priceFieldId ? document.getElementById(priceFieldId) : null
      ,libraryIdField: input.dataset.libraryIdField ? document.getElementById(input.dataset.libraryIdField) : null
    });
    
    // Mark as initialized
    input._itemAutocomplete = instance;
  });
}

window.initItemAutocomplete = initItemAutocomplete;

if (!window.__projectAlphaItemAutocompleteOutsideClickReady) {
  window.__projectAlphaItemAutocompleteOutsideClickReady = true;
  document.addEventListener('click', (e) => {
    document.querySelectorAll('[data-item-autocomplete]').forEach(input => {
      const instance = input._itemAutocomplete;
      if (instance && instance.dropdown && !input.contains(e.target) && !instance.dropdown.contains(e.target)) {
        instance.hideDropdown();
      }
    });
  });
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initItemAutocomplete);
} else {
  initItemAutocomplete();
}

if (!window.__projectAlphaItemAutocompletePageLoadedReady) {
  window.__projectAlphaItemAutocompletePageLoadedReady = true;
  document.addEventListener('pageLoaded', initItemAutocomplete);
}

// Expose globally for manual initialization
window.ItemAutocomplete = ItemAutocomplete;
