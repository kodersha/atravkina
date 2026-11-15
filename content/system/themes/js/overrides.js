/* Zoom */

mediumZoom(document.querySelectorAll('article img'), {
    background: '#000000',
    margin: 24,
    scrollOffset: 0,
})

/* Tumblr-style archive cards */
document.querySelectorAll('article.emerge[data-href]').forEach((article) => {
    if (article.dataset.cardNavigationReady === '1') return;
    article.dataset.cardNavigationReady = '1';

    let textGroup = null;

    [...article.childNodes].forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE &&
            (node.matches('figure, .blog-read') ||
             node.querySelector?.('img') && node.matches('figure'))) {
            textGroup = null;
            return;
        }

        if (node.nodeType === Node.TEXT_NODE && !node.textContent.trim()) {
            if (textGroup) textGroup.appendChild(node);
            return;
        }

        if (!textGroup) {
            textGroup = document.createElement('div');
            textGroup.className = 'post-text';
            textGroup.setAttribute('role', 'link');
            textGroup.setAttribute('tabindex', '0');
            article.insertBefore(textGroup, node);
        }

        textGroup.appendChild(node);
    });

    const textGroups = [...article.querySelectorAll(':scope > .post-text')];
    const hasText = textGroups.some((group) => group.textContent.trim().length > 0);
    article.classList.toggle('has-text', hasText);

    const contentElements = [...article.children].filter((element) =>
        !element.matches('.blog-read')
    );

    contentElements.forEach((element) => {
        if (!element.matches('figure')) return;
        element.classList.toggle('media-first', element === contentElements[0]);
        element.classList.toggle('media-last', element === contentElements[contentElements.length - 1]);
    });

    textGroups.forEach((group) => {
        const openPost = () => {
            window.location.href = article.dataset.href;
        };

        group.addEventListener('click', (event) => {
            if (event.target.closest(
                'a, button, input, textarea, select, label, [role="button"], [contenteditable="true"]'
            )) return;
            openPost();
        });

        group.addEventListener('keydown', (event) => {
            if (event.target !== group) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openPost();
            }
        });
    });
});
