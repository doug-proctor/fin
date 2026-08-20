import { X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface Props {
    id?: string;
    value: string[];
    /** Every tag already in use, offered as the dropdown's options. */
    suggestions: string[];
    onChange: (tags: string[]) => void;
}

/**
 * A tag is stored as the single word that follows a "#", so anything typed is
 * folded to that shape before it is added: leading hashes dropped, runs of
 * whitespace hyphenated, anything else that cannot appear in a tag removed,
 * and the rest lower cased.
 *
 * Mirrors TransactionData::normaliseTag, so a tag added here is exactly the
 * tag the server stores and exactly the tag the filter bar later offers.
 */
export function normaliseTag(input: string): string {
    return input
        .trim()
        .replace(/^#+/, '')
        .replace(/\s+/g, '-')
        .replace(/[^\p{L}\p{N}_-]+/gu, '')
        .toLowerCase();
}

/**
 * Multi-add tag field: the chosen tags sit in the field as removable chips,
 * and typing filters the tags already in use into a dropdown below it. Picking
 * one adds it; pressing Enter adds whatever was typed, which is how a new tag
 * is made.
 *
 * Built from the project's own primitives rather than a combobox library,
 * matching FacetedFilter.
 */
export function TagInput({ id, value, suggestions, onChange }: Props) {
    const [draft, setDraft] = useState('');
    const [open, setOpen] = useState(false);

    /**
     * Null until the user picks an option out of the list with the arrow keys
     * or the mouse. Nothing is highlighted by default, so Enter always adds
     * the text as typed rather than the option that happens to be first.
     */
    const [highlighted, setHighlighted] = useState<number | null>(null);

    const inputRef = useRef<HTMLInputElement>(null);

    /**
     * Wraps the field *and* its suggestion list, because that is what "outside
     * the component" is measured against below. Held on the field alone, a
     * click on an option read as a click outside: the list closed before the
     * option's own click could land, so picking a suggestion added the text
     * typed so far instead of the suggestion.
     */
    const containerRef = useRef<HTMLDivElement>(null);
    const listId = useId();

    const query = normaliseTag(draft);

    const matches = suggestions.filter(
        (tag) => !value.includes(tag) && (query === '' || tag.includes(query)),
    );

    /**
     * The typed text is only offered as a new tag when no existing tag is
     * spelled that way, so the list never shows the same tag twice.
     */
    const isNew =
        query !== '' && !value.includes(query) && !suggestions.includes(query);

    /** Options in the order the arrow keys walk them, the new tag last. */
    const options = isNew ? [...matches, query] : matches;
    const activeOption =
        highlighted === null ? undefined : options[highlighted];

    function add(tag: string) {
        const normalised = normaliseTag(tag);

        if (normalised !== '' && !value.includes(normalised)) {
            onChange([...value, normalised]);
        }

        setDraft('');
        setHighlighted(null);
    }

    /**
     * Leaving the field: close the list and add whatever was half typed, so
     * clicking Save with a tag still being typed cannot silently drop it.
     */
    function commit() {
        setOpen(false);
        add(draft);
    }

    /**
     * Leaving by clicking elsewhere, on mouseup.
     *
     * Neither of the two obvious places works. On blur nothing holds the focus
     * yet, and the dialog's focus trap answers any node removal it sees in that
     * state by taking the focus itself — which swallowed the click whole,
     * whether it was aimed at another field or at Save. On mousedown the focus
     * is safe, but adding the tag can wrap the field onto a second line, and
     * the button the press had landed on moves out from under the release.
     *
     * By mouseup the focus has settled and the click's target is already
     * fixed, so the field can grow and the click still lands where it was
     * aimed. React flushes between mouseup and click, so a form submitted by
     * that same click sees the tag.
     */
    useEffect(() => {
        function handleMouseUp(event: MouseEvent) {
            const target = event.target;

            if (
                target instanceof Node &&
                containerRef.current?.contains(target)
            ) {
                return;
            }

            commit();
        }

        document.addEventListener('mouseup', handleMouseUp, true);

        return () =>
            document.removeEventListener('mouseup', handleMouseUp, true);
    });

    function remove(tag: string) {
        onChange(value.filter((existing) => existing !== tag));
    }

    function move(step: number) {
        setOpen(true);

        if (options.length === 0) {
            return;
        }

        setHighlighted((current) => {
            if (current === null) {
                return step > 0 ? 0 : options.length - 1;
            }

            return (current + step + options.length) % options.length;
        });
    }

    function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        /**
         * Leaving by keyboard. Done here rather than on blur for the same
         * reason as the mouseup above: the field still holds the focus while a
         * keydown runs, so the redraw passes the focus trap unnoticed.
         */
        if (event.key === 'Tab') {
            commit();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            move(event.key === 'ArrowDown' ? 1 : -1);

            return;
        }

        /**
         * Enter adds a tag rather than submitting the dialog, which would
         * otherwise save the transaction the moment a tag was typed. With
         * nothing to add it is left alone, so Enter still submits.
         */
        if (event.key === 'Enter') {
            const tag = activeOption ?? draft;

            if (normaliseTag(tag) === '') {
                return;
            }

            event.preventDefault();
            add(tag);

            return;
        }

        /** A comma is the other way people end a tag. */
        if (event.key === ',') {
            event.preventDefault();
            add(draft);

            return;
        }

        /** Escape closes the list without also closing the dialog. */
        if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);

            return;
        }

        /** Backspace in an empty field takes the last chip off. */
        if (event.key === 'Backspace' && draft === '' && value.length > 0) {
            event.preventDefault();
            remove(value[value.length - 1]);
        }
    }

    return (
        <div ref={containerRef} className="relative">
            <div
                className={cn(
                    'flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-base shadow-xs transition-[color,box-shadow] md:text-sm',
                    'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                )}
                onMouseDown={(event) => {
                    /** Clicking the padding focuses the field without stealing it back. */
                    if (event.target === event.currentTarget) {
                        event.preventDefault();
                        inputRef.current?.focus();
                    }
                }}
            >
                {value.map((tag) => (
                    <Badge
                        key={tag}
                        variant="secondary"
                        className="gap-1 py-0.5 pr-1 text-sm"
                    >
                        #{tag}
                        <button
                            type="button"
                            aria-label={`Remove #${tag}`}
                            onClick={() => remove(tag)}
                            className="rounded-sm opacity-60 transition-opacity hover:opacity-100"
                        >
                            <X className="h-3 w-3" />
                        </button>
                    </Badge>
                ))}

                <input
                    id={id}
                    ref={inputRef}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && highlighted !== null
                            ? `${listId}-${highlighted}`
                            : undefined
                    }
                    value={draft}
                    maxLength={50}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setHighlighted(null);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={handleKeyDown}
                    className="min-w-24 flex-1 bg-transparent px-1 py-0.5 outline-none placeholder:text-muted-foreground"
                    placeholder={value.length === 0 ? 'Add a tag' : undefined}
                />
            </div>

            {open && options.length > 0 && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {options.map((option, index) => (
                        <li
                            key={option}
                            id={`${listId}-${index}`}
                            role="option"
                            aria-selected={index === highlighted}
                            /** Keeps the field focused so the blur handler cannot fire first. */
                            onMouseDown={(event) => event.preventDefault()}
                            onMouseEnter={() => setHighlighted(index)}
                            onClick={() => {
                                add(option);
                                inputRef.current?.focus();
                            }}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm',
                                index === highlighted &&
                                    'bg-accent text-accent-foreground',
                            )}
                        >
                            <span className="truncate">#{option}</span>
                            {isNew && index === options.length - 1 && (
                                <span className="ml-auto text-xs text-muted-foreground">
                                    New
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
