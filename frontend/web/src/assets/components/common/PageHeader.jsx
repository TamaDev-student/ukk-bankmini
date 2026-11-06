/**
 * PageHeader — Komponen header standar halaman operasional.
 */
function PageHeader({ title, badge, description, action }) {
    return (
        <div className="flex flex-col justify-between gap-4 border-b border-outline-variant/30 py-5 sm:py-6 lg:flex-row lg:items-center">
            <div className="flex min-w-0 flex-col">
                <div className="mb-1 flex min-w-0 flex-wrap items-center gap-2">
                    <h1 className="break-words font-headline-lg text-headline-lg font-w-headline-lg font-semibold tracking-tight text-on-surface">
                        {title}
                    </h1>
                    {badge && (
                        <span className="px-2.5 py-0.5 rounded-full font-label-sm text-label-sm font-w-label-sm bg-primary/10 text-primary font-semibold">
                            {badge}
                        </span>
                    )}
                </div>
                {description && (
                    <p className="font-body-sm text-body-sm font-w-body-sm text-secondary mt-0.5 max-w-3xl">
                        {description}
                    </p>
                )}
            </div>
            {action && (
                <div className="flex w-full shrink-0 flex-wrap items-center gap-3 [&>*]:max-w-full lg:w-auto">
                    {action}
                </div>
            )}
        </div>
    );
}

export default PageHeader;
