import {
  animate,
  splitText,
  stagger,
} from 'https://cdn.jsdelivr.net/npm/animejs@4.5.0/+esm';

const title = document.querySelector('.hero-title');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

if (title && !reducedMotion) {
  // Line measurements must happen after the web font has settled. Anime.js will
  // automatically rebuild these wrappers if responsive layout changes the lines.
  await document.fonts.ready;

  const split = splitText(title, {
    lines: {
      wrap: 'clip',
      class: 'hero-title-line',
    },
    accessible: true,
  });

  split.addEffect(({ lines }) => animate(lines, {
    y: { from: '110%', to: '0%' },
    opacity: { from: 0, to: 1 },
    duration: 850,
    delay: stagger(145),
    ease: 'out(4)',
  }));
}
