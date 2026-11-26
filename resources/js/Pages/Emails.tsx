import { Link, router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { KINDS } from "@/Components/site/Footer";
import SendButton, { SendState } from "@/Components/site/SendButton";
import SiteLayout from "@/Layouts/SiteLayout";

type Props = {
    email?: string;
    kinds?: string[];
    token?: string;
    stopped?: string;
};

/** You're off the list: shown once, straight after stopping. */
function Off({ email }: { email: string }) {
    return (
        <div className="pm-v">
            <div className="fp-top">
                <h1>You’re off the list</h1>
            </div>
            <p className="pm-p">
                I’ve taken {email} off my list and deleted it, so these emails
                stop here. Thank you for reading.
            </p>
            <p className="pm-p">
                Changed your mind?{" "}
                <Link className="flink" href="/#nl">
                    Subscribe again
                </Link>
            </p>
        </div>
    );
}

/** What you get: the kinds of new work this address receives; or stop them all. */
export default function Emails({ email, kinds = [], token, stopped }: Props) {
    const [picked, setPicked] = useState(kinds);
    const [saved, setSaved] = useState(kinds);
    const [state, setState] = useState<SendState>("idle");
    const [stopping, setStopping] = useState(false);
    const timer = useRef(0);

    useEffect(() => () => clearTimeout(timer.current), []);

    if (stopped) {
        return (
            <SiteLayout title="You’re off the list" newsletter={false}>
                <div className="wrap fp-w pm">
                    <Off email={stopped} />
                </div>
            </SiteLayout>
        );
    }

    const same = [...picked].sort().join() === [...saved].sort().join();
    const toggle = (key: string) => {
        setState("idle");
        setPicked((list) =>
            list.includes(key) ? list.filter((k) => k !== key) : [...list, key],
        );
    };

    const save = () => {
        if (same || picked.length === 0 || state === "sending") return;
        setState("sending");
        const started = Date.now();
        const after = (fn: () => void) =>
            (timer.current = window.setTimeout(
                fn,
                Math.max(0, 900 - (Date.now() - started)),
            ));
        router.put(
            `/emails/${token}`,
            { kinds: picked },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    after(() => {
                        setSaved(picked);
                        setState("sent");
                        timer.current = window.setTimeout(
                            () => setState("idle"),
                            2400,
                        );
                    }),
                onError: () => after(() => setState("failed")),
            },
        );
    };

    const stop = () => {
        if (stopping) return;
        setStopping(true);
        router.delete(`/emails/${token}`, {
            onFinish: () => setStopping(false),
        });
    };

    return (
        <SiteLayout title="What you get" newsletter={false}>
            <div className="wrap fp-w pm">
                <div className="pm-v">
                    <div className="fp-top">
                        <h1>What you get</h1>
                    </div>
                    <p className="pm-em">{email}</p>
                    <div
                        className="checks"
                        role="group"
                        aria-label="What you get"
                    >
                        {KINDS.map((k) => (
                            <label key={k.key} className="cb">
                                <input
                                    type="checkbox"
                                    value={k.key}
                                    checked={picked.includes(k.key)}
                                    onChange={() => toggle(k.key)}
                                />
                                {k.label}
                            </label>
                        ))}
                    </div>
                    <div className="pm-go">
                        <SendButton
                            className="pm-b"
                            type="button"
                            state={state}
                            sentLabel="Saved"
                            retryLabel="Try again"
                            disabled={
                                state === "idle" &&
                                (same || picked.length === 0)
                            }
                            onClick={save}
                        >
                            Save
                        </SendButton>
                        <button
                            className="pm-l"
                            type="button"
                            onClick={stop}
                            disabled={stopping}
                        >
                            <span className="lbl">Stop all emails</span>
                        </button>
                    </div>
                </div>
            </div>
        </SiteLayout>
    );
}
