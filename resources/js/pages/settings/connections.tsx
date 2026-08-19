import { Form, Head } from '@inertiajs/react';
import { AlertTriangle, Landmark, ShieldCheck } from 'lucide-react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { edit as editConnections } from '@/routes/connections';
import { connect, disconnect, retry } from '@/routes/monzo';

type ConnectionStatus = 'connected' | 'pending_approval' | 'disconnected';

interface MonzoConnection {
    status: ConnectionStatus;
    canRefresh: boolean;
    lastSyncedAt: string | null;
    lastSyncError: string | null;
}

interface ConnectedAccount {
    id: number;
    name: string;
    provider: string;
    type: string | null;
    transactionsCount: number;
    lastSyncedAt: string | null;
}

interface Props {
    configured: boolean;
    monzo: MonzoConnection | null;
    accounts: ConnectedAccount[];
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export default function Connections({ configured, monzo, accounts }: Props) {
    const isConnected = monzo?.status === 'connected';
    const isPendingApproval = monzo?.status === 'pending_approval';

    return (
        <>
            <Head title="Connections" />

            <h1 className="sr-only">Connections</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Connections"
                    description="Manage the accounts that feed transactions into your ledger"
                />

                {!configured && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Monzo is not configured</AlertTitle>
                        <AlertDescription>
                            {/* One paragraph, because AlertDescription lays its children out as grid rows. */}
                            <p>
                                Set <code>MONZO_CLIENT_ID</code> and{' '}
                                <code>MONZO_CLIENT_SECRET</code> in your{' '}
                                <code>.env</code>. Register the client as{' '}
                                <strong>confidential</strong> in the Monzo
                                developer portal, otherwise no refresh token is
                                issued and the connection will stop working when
                                the token expires.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                <section className="space-y-4">
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <Landmark className="mt-1 h-5 w-5 text-muted-foreground" />
                            <div>
                                <h2 className="font-medium">Monzo</h2>
                                <p className="text-sm text-muted-foreground">
                                    Your Monzo current account, synced over the
                                    Monzo API.
                                </p>
                            </div>
                        </div>

                        {monzo && (
                            <Badge
                                variant={
                                    isConnected
                                        ? 'default'
                                        : isPendingApproval
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {isConnected
                                    ? 'Connected'
                                    : isPendingApproval
                                      ? 'Awaiting approval'
                                      : 'Disconnected'}
                            </Badge>
                        )}
                    </div>

                    {isPendingApproval && (
                        <Alert>
                            <ShieldCheck className="h-4 w-4" />
                            <AlertTitle>
                                Approve access in your Monzo app
                            </AlertTitle>
                            <AlertDescription className="space-y-3">
                                <p>
                                    Monzo has sent you a push notification.
                                    Approve it, then retry &mdash; and do it
                                    promptly. Monzo only serves transactions
                                    older than 90 days for about five minutes
                                    after you connect.
                                </p>
                                <Form {...retry.form()}>
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            I&rsquo;ve approved it &mdash; retry
                                        </Button>
                                    )}
                                </Form>
                            </AlertDescription>
                        </Alert>
                    )}

                    {isConnected && !monzo.canRefresh && (
                        <Alert variant="destructive">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle>No refresh token was issued</AlertTitle>
                            <AlertDescription>
                                Monzo only issues refresh tokens to confidential
                                clients. This connection will stop working when
                                the access token expires. Mark the client as
                                confidential in the Monzo developer portal and
                                reconnect.
                            </AlertDescription>
                        </Alert>
                    )}

                    {monzo?.lastSyncError && (
                        <Alert variant="destructive">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle>The last sync failed</AlertTitle>
                            <AlertDescription>
                                {monzo.lastSyncError}
                            </AlertDescription>
                        </Alert>
                    )}

                    {isConnected && (
                        <p className="text-sm text-muted-foreground">
                            Last synced {formatDateTime(monzo.lastSyncedAt)}
                        </p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        {isConnected ? (
                            <>
                                <Form
                                    {...disconnect.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Disconnect
                                        </Button>
                                    )}
                                </Form>
                            </>
                        ) : (
                            <Button
                                asChild
                                className={cn(
                                    !configured &&
                                        'pointer-events-none opacity-50',
                                )}
                            >
                                {/*
                                 * A plain anchor, not an Inertia Link: the OAuth
                                 * handoff must be a top level navigation, or the
                                 * browser blocks the cross origin redirect to
                                 * auth.monzo.com. An anchor also ignores the
                                 * disabled prop, hence the explicit styling.
                                 */}
                                <a
                                    href={connect.url()}
                                    aria-disabled={!configured}
                                    tabIndex={configured ? undefined : -1}
                                >
                                    {monzo
                                        ? 'Reconnect Monzo'
                                        : 'Connect Monzo'}
                                </a>
                            </Button>
                        )}
                    </div>
                </section>

                {accounts.length > 0 && (
                    <>
                        <Separator />

                        <section className="space-y-3">
                            <h2 className="font-medium">Accounts</h2>

                            <div className="divide-y rounded-lg border">
                                {accounts.map((account) => (
                                    <div
                                        key={account.id}
                                        className="flex items-center justify-between gap-4 p-4"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {account.name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {account.transactionsCount.toLocaleString()}{' '}
                                                transactions &middot; synced{' '}
                                                {formatDateTime(
                                                    account.lastSyncedAt,
                                                )}
                                            </p>
                                        </div>
                                        <Badge variant="outline">
                                            {account.provider}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

Connections.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: editConnections(),
        },
    ],
};
