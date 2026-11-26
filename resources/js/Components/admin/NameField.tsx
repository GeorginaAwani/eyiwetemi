import { useEffect, useRef, useState } from 'react';
import { prefersReducedMotion } from '../../lib/motion';
import { FieldError, Unfold } from './Controls';
import { Ring } from './Icons';

/**
 * Name something on the spot (a new series or collection, or a series' new name): a field, Create (or Save) and Cancel.
 * `onCreate` answers with an error to show, or null when done.
 */
export default function NameField({
    show,
    placeholder,
    initial = '',
    action = 'Create',
    onCreate,
    onCancel,
}: {
    show: boolean;
    placeholder: string;
    initial?: string;
    action?: string;
    onCreate: (name: string) => Promise<string | null>;
    onCancel: () => void;
}) {
    const [value, setValue] = useState(initial);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!show) {
            return;
        }
        setValue(initial);
        setError('');
        const t = window.setTimeout(() => {
            input.current?.focus();
            input.current?.select();
        }, prefersReducedMotion() ? 0 : 200);

        return () => window.clearTimeout(t);
    }, [show, initial]);

    async function done() {
        const v = value.trim().replace(/\s+/g, ' ');
        if (!v) {
            input.current?.focus();

            return;
        }
        setBusy(true);
        const problem = await onCreate(v);
        setBusy(false);
        setError(problem ?? '');
        if (problem) {
            input.current?.focus();
        }
    }

    return (
        <Unfold show={show}>
            <div className="w-sen">
                <span className={`k-in${error ? ' bad' : ''}`}>
                    <input
                        ref={input}
                        placeholder={placeholder}
                        aria-label={placeholder}
                        autoComplete="off"
                        value={value}
                        onChange={(e) => {
                            setValue(e.target.value);
                            setError('');
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                done();
                            }
                            if (e.key === 'Escape') {
                                e.preventDefault();
                                e.stopPropagation();
                                onCancel();
                            }
                        }}
                    />
                </span>
                <FieldError message={error} />
                <div className="acts">
                    <button className={`k-btn sm${busy ? ' busy' : ''}`} type="button" aria-busy={busy} onClick={done}>
                        <span>{action}</span>
                        <Ring />
                    </button>
                    <button className="w-act" type="button" onClick={onCancel}>
                        Cancel
                    </button>
                </div>
            </div>
        </Unfold>
    );
}
