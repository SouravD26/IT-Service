// Presentation-only enhancement: moves each dashboard card's leading emoji
// out of the title text and into a small rounded pastel icon badge above it.
// Does not touch any link, href, form, or data — titles remain the same words.
document.addEventListener('DOMContentLoaded', function () {
    var palette = ['blue', 'green', 'purple', 'orange', 'pink', 'teal', 'yellow', 'red'];
    var emojiRe = /^([\p{Extended_Pictographic}️‍]+)\s*/u;
    var titles = document.querySelectorAll('.dashboard-card .card-title');

    titles.forEach(function (title, i) {
        var body = title.parentElement;
        if (!body || body.querySelector('.dm-icon-badge')) return;

        var color = palette[i % palette.length];
        var text = title.textContent;
        var match = text.match(emojiRe);

        var badge = document.createElement('div');
        badge.className = 'dm-icon-badge dm-badge-' + color;
        badge.textContent = match ? match[1].trim() : '⭐';
        badge.setAttribute('aria-hidden', 'true');

        body.insertBefore(badge, title);

        if (match) {
            title.textContent = text.slice(match[0].length);
        }

        var card = title.closest('.dashboard-card');
        if (card) card.classList.add('dm-accent-' + color);
    });
});
