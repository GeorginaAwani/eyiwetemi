/** Keep closed menus and panels out of the tab order (React 18 has no typed `inert` prop). */
export const inertWhen = (closed: boolean): Record<string, string> => (closed ? { inert: '' } : {});
