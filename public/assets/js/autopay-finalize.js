(() => {
  function bind() {
    document.querySelectorAll('select[data-autopay-choice]').forEach(select => {
      if (select.dataset.bound) return;
      select.dataset.bound = '1';
      const update = () => {
        const button = select.form?.querySelector('button[type="submit"]');
        if (!button) return;
        button.textContent = select.value === 'collect'
          ? 'Finalize & initiate payment'
          : select.value === 'hold' ? 'Finalize without AutoPay' : 'Choose collection before finalizing';
      };
      select.addEventListener('change', update);
      update();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})();
