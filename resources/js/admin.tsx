import "../css/admin.css";

import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createRoot } from "react-dom/client";
import { wirePageChange } from "./lib/motion";
import { startOffline } from "./lib/offline";

createInertiaApp({
    title: (title) => (title ? `${title} · eyiwetemi admin` : "eyiwetemi · admin"),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob("./Pages/Admin/**/*.tsx"),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    /* no bar loader: the page goes faint and the wordmark's dots bob, as on the reading site */
    progress: false,
});

wirePageChange();
startOffline();
