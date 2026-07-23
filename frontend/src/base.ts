const appMarker = '/app';
const markerIndex = window.location.pathname.indexOf(appMarker);

/** Hosting prefix before /app, e.g. "/ezihgebeya" locally and "" at a root domain. */
export const publicBase = markerIndex >= 0
  ? window.location.pathname.slice(0, markerIndex).replace(/\/$/, '')
  : '';

export const appBase = `${publicBase}/app`;
export const apiBase = `${publicBase}/api/v1`;
