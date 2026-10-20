import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api/client';
import DateRangeControl from './DateRangeControl';
import { LoadingSpinner, ErrorMessage } from './LoadingSpinner';

const { fyStart, fyEnd } = window.qfdData || {};

function fmt( v ) {
    return new Intl.NumberFormat( 'en-GB', { style: 'currency', currency: 'GBP' } ).format( v ?? 0 );
}

// RAG against budget: remaining = budget − spend
// green  = remaining ≥ 0     (on / under budget)
// amber  = remaining ≥ −500  (within £500 over)
// red    = remaining < −500  (significantly over)
function ragStatus( remaining ) {
    if ( remaining >= 0 )    return 'green';
    if ( remaining >= -500 ) return 'amber';
    return 'red';
}

const RAG_LABELS = {
    green: __( '● Under Budget', 'quickfile-dashboard' ),
    amber: __( '● Near Limit',   'quickfile-dashboard' ),
    red:   __( '● Over Budget',  'quickfile-dashboard' ),
};

// ─── Inline budget editor ────────────────────────────────────────────────────

function BudgetEditor( { tag, budget, onSaved } ) {
    const [ editing,   setEditing   ] = useState( false );
    const [ inputVal,  setInputVal  ] = useState( String( budget ) );
    const [ saving,    setSaving    ] = useState( false );
    const [ saveError, setSaveError ] = useState( null );

    // Keep input in sync when parent updates the budget prop
    useEffect( () => {
        if ( ! editing ) setInputVal( String( budget ) );
    }, [ budget, editing ] );

    const handleSave = async () => {
        const parsed = parseFloat( inputVal );
        if ( isNaN( parsed ) || parsed < 0 ) return;
        setSaving( true );
        setSaveError( null );
        try {
            await api.saveProjectBudget( tag, parsed );
            onSaved( tag, parsed );
            setEditing( false );
        } catch ( e ) {
            setSaveError( e.message );
        } finally {
            setSaving( false );
        }
    };

    const handleCancel = () => {
        setEditing( false );
        setInputVal( String( budget ) );
        setSaveError( null );
    };

    if ( ! editing ) {
        return (
            <div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
                <span style={ { fontSize: '1.25rem', fontWeight: 700, color: 'var(--navy)' } }>
                    { budget > 0 ? fmt( budget ) : <em style={ { color: 'var(--muted)', fontWeight: 400, fontSize: '1rem' } }>{ __( 'Not set', 'quickfile-dashboard' ) }</em> }
                </span>
                <button
                    className="wb-btn wb-btn--secondary"
                    style={ { fontSize: '.75rem', padding: '3px 10px' } }
                    onClick={ () => setEditing( true ) }
                    aria-label={ __( 'Edit budget', 'quickfile-dashboard' ) }
                >
                    { __( 'Edit', 'quickfile-dashboard' ) }
                </button>
            </div>
        );
    }

    return (
        <div style={ { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' } }>
            <span style={ { fontSize: '.875rem', color: 'var(--muted)' } }>£</span>
            <input
                type="number"
                min="0"
                step="0.01"
                value={ inputVal }
                onChange={ ( e ) => setInputVal( e.target.value ) }
                style={ { width: 120, padding: '5px 8px', border: '1px solid var(--border)', borderRadius: 4, fontSize: '.875rem' } }
                autoFocus
                onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) handleSave(); if ( e.key === 'Escape' ) handleCancel(); } }
            />
            <button
                className="wb-btn"
                style={ { fontSize: '.75rem', padding: '4px 12px' } }
                onClick={ handleSave }
                disabled={ saving }
            >
                { saving ? __( 'Saving…', 'quickfile-dashboard' ) : __( 'Save', 'quickfile-dashboard' ) }
            </button>
            <button
                className="wb-btn wb-btn--secondary"
                style={ { fontSize: '.75rem', padding: '4px 10px' } }
                onClick={ handleCancel }
                disabled={ saving }
            >
                { __( 'Cancel', 'quickfile-dashboard' ) }
            </button>
            { saveError && (
                <span style={ { color: 'var(--rag-red)', fontSize: '.8125rem' } }>{ saveError }</span>
            ) }
        </div>
    );
}

// ─── Shared bits ──────────────────────────────────────────────────────────────

const STAT_LABEL = { fontSize: '.75rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 4 };
const STAT_VALUE = { fontSize: '1.25rem', fontWeight: 700, color: 'var(--navy)' };

function Stat( { label, value, colour } ) {
    return (
        <div>
            <div style={ STAT_LABEL }>{ label }</div>
            <div style={ { ...STAT_VALUE, color: colour ?? STAT_VALUE.color } }>{ value }</div>
        </div>
    );
}

function netColour( v ) {
    return v >= 0 ? 'var(--rag-green)' : 'var(--rag-red)';
}

// Table of the documents behind an income or spend total.
function DocumentTable( { rows, total, partyLabel, getKey, getNumber, getDate, getParty } ) {
    return (
        <div className="wb-table-wrap" style={ { marginTop: 12 } }>
            <table className="wb-table">
                <thead>
                    <tr>
                        <th>{ __( 'Number',      'quickfile-dashboard' ) }</th>
                        <th>{ __( 'Date',        'quickfile-dashboard' ) }</th>
                        <th>{ partyLabel }</th>
                        <th>{ __( 'Description', 'quickfile-dashboard' ) }</th>
                        <th>{ __( 'Amount',      'quickfile-dashboard' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { rows.map( ( row, i ) => (
                        <tr key={ getKey( row ) ?? i }>
                            <td>{ getNumber( row ) || '—' }</td>
                            <td>{ getDate( row ) || '—' }</td>
                            <td>{ getParty( row ) || '—' }</td>
                            <td>{ row.Description || '—' }</td>
                            <td>{ fmt( row.GrossAmount ?? 0 ) }</td>
                        </tr>
                    ) ) }
                </tbody>
                <tfoot>
                    <tr>
                        <td colSpan={ 4 }>{ __( 'Total', 'quickfile-dashboard' ) }</td>
                        <td>{ fmt( total ) }</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

// ─── Project card ─────────────────────────────────────────────────────────────

function ProjectCard( { project, budget, onBudgetSaved } ) {
    const [ expanded, setExpanded ] = useState( null ); // null | 'income' | 'spend'

    const name      = project.tag || __( 'Unknown Project', 'quickfile-dashboard' );
    const income    = parseFloat( project.income ?? 0 );
    const spend     = parseFloat( project.spend  ?? 0 );
    const net       = income - spend;
    const invoices  = project.invoices  ?? [];
    const purchases = project.purchases ?? [];
    const hasBudget = budget > 0;
    const remaining = hasBudget ? budget - spend : null;
    const pct       = hasBudget ? Math.min( ( spend / budget ) * 100, 100 ) : null;
    const rag       = remaining !== null ? ragStatus( remaining ) : null;

    const barColour = rag === 'red' ? 'var(--rag-red)' : rag === 'amber' ? 'var(--gold)' : 'var(--teal)';

    const toggle = ( section ) => setExpanded( expanded === section ? null : section );

    return (
        <div className="wb-card" style={ { marginBottom: 16 } }>

            {/* ── Header ── */}
            <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, marginBottom: 16 } }>
                <div className="wb-card__title" style={ { marginBottom: 0, paddingBottom: 0, border: 'none' } }>
                    { name }
                </div>
                { rag && (
                    <span className={ `wb-rag wb-rag--${ rag }` }>{ RAG_LABELS[ rag ] }</span>
                ) }
            </div>

            {/* ── Stats row ── */}
            <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 16, marginBottom: pct !== null ? 14 : 0 } }>

                <Stat label={ __( 'Income', 'quickfile-dashboard' ) } value={ fmt( income ) } colour="var(--rag-green)" />
                <Stat label={ __( 'Spend',  'quickfile-dashboard' ) } value={ fmt( spend ) }  colour="var(--rag-red)" />
                <Stat label={ __( 'Net',    'quickfile-dashboard' ) } value={ fmt( net ) }    colour={ netColour( net ) } />

                <div>
                    <div style={ STAT_LABEL }>{ __( 'Budget', 'quickfile-dashboard' ) }</div>
                    <BudgetEditor tag={ project.tag } budget={ budget } onSaved={ onBudgetSaved } />
                </div>

                { hasBudget && (
                    <Stat label={ __( 'Budget Remaining', 'quickfile-dashboard' ) } value={ fmt( remaining ) } colour={ netColour( remaining ) } />
                ) }

                { hasBudget && pct !== null && (
                    <div style={ { alignSelf: 'center' } }>
                        <div style={ { ...STAT_LABEL, marginBottom: 6 } }>
                            { __( 'Budget used', 'quickfile-dashboard' ) }
                        </div>
                        <div style={ { height: 10, background: 'var(--border)', borderRadius: 5, overflow: 'hidden' } }>
                            <div style={ {
                                height: '100%',
                                width: `${ pct }%`,
                                background: barColour,
                                borderRadius: 5,
                                transition: 'width .4s',
                            } } />
                        </div>
                        <div style={ { fontSize: '.75rem', color: 'var(--muted)', marginTop: 4 } }>
                            { pct.toFixed( 1 ) }%
                        </div>
                    </div>
                ) }
            </div>

            {/* ── Document accordions ── */}
            { ( invoices.length > 0 || purchases.length > 0 ) && (
                <div style={ { display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 } }>
                    { invoices.length > 0 && (
                        <button
                            className="wb-btn wb-btn--secondary"
                            style={ { fontSize: '.8125rem', padding: '5px 14px' } }
                            onClick={ () => toggle( 'income' ) }
                            aria-expanded={ expanded === 'income' }
                        >
                            { expanded === 'income'
                                ? __( 'Hide Income', 'quickfile-dashboard' )
                                : `${ __( 'Show', 'quickfile-dashboard' ) } ${ invoices.length } ${ __( 'sales invoice(s)', 'quickfile-dashboard' ) }` }
                        </button>
                    ) }
                    { purchases.length > 0 && (
                        <button
                            className="wb-btn wb-btn--secondary"
                            style={ { fontSize: '.8125rem', padding: '5px 14px' } }
                            onClick={ () => toggle( 'spend' ) }
                            aria-expanded={ expanded === 'spend' }
                        >
                            { expanded === 'spend'
                                ? __( 'Hide Spend', 'quickfile-dashboard' )
                                : `${ __( 'Show', 'quickfile-dashboard' ) } ${ purchases.length } ${ __( 'purchase(s)', 'quickfile-dashboard' ) }` }
                        </button>
                    ) }
                </div>
            ) }

            { expanded === 'income' && (
                <DocumentTable
                    rows={ invoices }
                    total={ income }
                    partyLabel={ __( 'Client', 'quickfile-dashboard' ) }
                    getKey={ ( r ) => r.InvoiceID }
                    getNumber={ ( r ) => r.InvoiceNumber }
                    getDate={ ( r ) => r.IssueDate }
                    getParty={ ( r ) => r.ClientName }
                />
            ) }

            { expanded === 'spend' && (
                <DocumentTable
                    rows={ purchases }
                    total={ spend }
                    partyLabel={ __( 'Supplier', 'quickfile-dashboard' ) }
                    getKey={ ( r ) => r.PurchaseID }
                    getNumber={ ( r ) => r.ReceiptNumber }
                    getDate={ ( r ) => r.ReceiptDate }
                    getParty={ ( r ) => r.SupplierName }
                />
            ) }
        </div>
    );
}

// ─── Summary bar ─────────────────────────────────────────────────────────────

function SummaryBar( { projects, budgets } ) {
    const totalIncome = projects.reduce( ( s, p ) => s + parseFloat( p.income ?? 0 ), 0 );
    const totalSpend  = projects.reduce( ( s, p ) => s + parseFloat( p.spend  ?? 0 ), 0 );
    const totalBudget = projects.reduce( ( s, p ) => s + ( budgets[ p.tag ] ?? 0 ), 0 );
    const net         = totalIncome - totalSpend;

    const label = { fontSize: '.75rem', color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.05em' };
    const value = { fontSize: '1.75rem', fontWeight: 800, color: 'var(--navy)' };

    return (
        <div className="wb-card" style={ { marginBottom: 24 } }>
            <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 20 } }>
                <div>
                    <div style={ label }>{ __( 'Total Projects', 'quickfile-dashboard' ) }</div>
                    <div style={ value }>{ projects.length }</div>
                </div>
                <div>
                    <div style={ label }>{ __( 'Total Income', 'quickfile-dashboard' ) }</div>
                    <div style={ { ...value, color: 'var(--rag-green)' } }>{ fmt( totalIncome ) }</div>
                </div>
                <div>
                    <div style={ label }>{ __( 'Total Spend', 'quickfile-dashboard' ) }</div>
                    <div style={ { ...value, color: 'var(--rag-red)' } }>{ fmt( totalSpend ) }</div>
                </div>
                <div>
                    <div style={ label }>{ __( 'Net', 'quickfile-dashboard' ) }</div>
                    <div style={ { ...value, color: netColour( net ) } }>{ fmt( net ) }</div>
                </div>
                <div>
                    <div style={ label }>{ __( 'Total Budget', 'quickfile-dashboard' ) }</div>
                    <div style={ value }>{ totalBudget > 0 ? fmt( totalBudget ) : '—' }</div>
                </div>
            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Projects() {
    const [ projects, setProjects ] = useState( null );
    const [ budgets,  setBudgets  ] = useState( {} );
    const [ loading,  setLoading  ] = useState( false );
    const [ error,    setError    ] = useState( null );

    const fetchData = ( from, to ) => {
        setLoading( true );
        setError( null );
        Promise.all( [ api.getProjects( from, to ), api.getProjectBudgets() ] )
            .then( ( [ proj, budg ] ) => {
                setProjects( proj );
                setBudgets( budg );
            } )
            .catch( ( e ) => setError( e.message ) )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => { fetchData( fyStart, fyEnd ); }, [] );

    const handleBudgetSaved = useCallback( ( tag, newBudget ) => {
        setBudgets( ( prev ) => ( { ...prev, [ tag ]: newBudget } ) );
    }, [] );

    return (
        <div>
            <DateRangeControl onFetch={ fetchData } loading={ loading } />
            { error && <ErrorMessage message={ error } /> }
            { loading && <LoadingSpinner /> }

            { ! loading && projects && (
                <>
                    <SummaryBar projects={ projects } budgets={ budgets } />

                    { projects.map( ( project, i ) => (
                        <ProjectCard
                            key={ project.tag ?? i }
                            project={ project }
                            budget={ budgets[ project.tag ] ?? 0 }
                            onBudgetSaved={ handleBudgetSaved }
                        />
                    ) ) }

                    { projects.length === 0 && (
                        <p className="wb-empty">
                            { __( 'No projects found for this period.', 'quickfile-dashboard' ) }
                        </p>
                    ) }
                </>
            ) }

            { ! loading && ! projects && ! error && (
                <p className="wb-empty">
                    { __( 'Select a date range and click Load Data.', 'quickfile-dashboard' ) }
                </p>
            ) }
        </div>
    );
}
