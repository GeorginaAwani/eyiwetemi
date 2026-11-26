import "../css/app.css";

import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createRoot } from "react-dom/client";
import { wirePageChange } from "./lib/motion";

createInertiaApp({
    title: (title) => (title ? `${title} · eyiwetemi` : "eyiwetemi"),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob("./Pages/**/*.tsx"),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    /* no bar loader: the page change is the old page going faint and the wordmark's dots bobbing */
    progress: false,
});

wirePageChange();
