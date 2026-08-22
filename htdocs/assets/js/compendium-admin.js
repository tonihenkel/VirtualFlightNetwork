(function () {
    var form = document.querySelector('main form');
    if (!form || !form.querySelector('textarea[name="content_html"]')) return;
    var status = form.querySelector('select[name="status"]');
    if (!status) return;

    var holder = document.createElement('label');
    holder.className = 'wide';
    holder.style.display = 'flex';
    holder.style.alignItems = 'center';
    holder.style.gap = '10px';
    holder.style.padding = '12px';
    holder.style.border = '1px solid #34729b';
    var loader = document.currentScript;
    var label = loader && loader.dataset.label ? loader.dataset.label : 'Use as compendium home page';
    var checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'is_homepage';
    checkbox.value = '1';
    checkbox.style.width = 'auto';
    checkbox.style.margin = '0';
    var text = document.createElement('span');
    text.textContent = label;
    holder.appendChild(checkbox);
    holder.appendChild(text);
    status.closest('label').after(holder);

    checkbox.checked = !!(loader && loader.dataset.homepage === '1');
}());
