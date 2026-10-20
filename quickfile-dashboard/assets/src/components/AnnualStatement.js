import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api/client';
import { LoadingSpinner, ErrorMessage } from './LoadingSpinner';
import { useFY } from '../FYContext';

const { isAdmin, selectedAccounts = [] } = window.qfdData || {};

function fyLabel( startYear ) {
    return `${ startYear }/${ String( startYear + 1 ).slice( -2 ) }`;
}

function parseFYStart( dateStr ) {
    return parseInt( ( dateStr ?? '' ).split( '-' )[ 0 ] ) || new Date().getFullYear();
}

function fmt( v ) {
    return new Intl.NumberFormat( 'en-GB', { style: 'currency', currency: 'GBP' } ).format( v ?? 0 );
}

// ─── Inline prior-year cell (admin editable) ──────────────────────────────────────────────

function PriorYearCell( { fy, code, value, onSaved } ) {
    const [ editing,  setEditing  ] = useState( false );
    const [ inputVal, setInputVal ] = useState( '' );
    const [ saving,   setSaving   ] = useState( false );
    const [ saveErr,  setSaveErr  ] = useState( null );

    const startEdit = () => {
        setInputVal( value != null ? String( value ) : '' );
        setSaveErr( null );
        setEditing( true );
    };

    const handleSave = async () => {
        const parsed = parseFloat( inputVal );
        if ( isNaN( parsed ) ) { setEditing( false ); return; }
        setSaving( true );
        try {
            await api.savePriorYearFigure( fy, code, parsed );
            onSaved( code, parsed );
            setEditing( false );
        } catch ( e ) {
            setSaveErr( e.message );
        } finally {
            setSaving( false );
        }
    };

    const handleKey = ( e ) => {
        if ( e.key === 'Enter'  ) handleSave();
        if ( e.key === 'Escape' ) setEditing( false );
    };

    if ( ! isAdmin ) {
        return (
            <td>
                { value != null ? fmt( value ) : <span style={ { color: 'var(--muted)' } }>—</span> }
            </td>
        );
    }

    if ( editing ) {
        return (
            <td style={ { padding: '4px 8px' } }>
                <div style={ { display: 'flex', alignItems: 'center', gap: 4, justifyContent: 'flex-end' } }>
                    <input
                        type="number"
                        step="0.01"
                        value={ inputVal }
                        onChange={ ( e ) => setInputVal( e.target.value ) }
                        onKeyDown={ handleKey }
                        autoFocus
                        style={ { width: 90, padding: '2px 5px', fontSize: '.8125rem', border: '1px solid var(--teal)', borderRadius: 3 } }
                    />
                    <button
                        onClick={ handleSave }
                        disabled={ saving }
                        title={ __( 'Save', 'quickfile-dashboard' ) }
                        style={ { background: 'var(--teal)', color: '#fff', border: 'none', borderRadius: 3, padding: '2px 7px', cursor: 'pointer', fontSize: '.875rem' } }
                    >
                        { saving ? '…' : '✓' }
                    </button>
                    <button
                        onClick={ () => setEditing( false ) }
                        title={ __( 'Cancel', 'quickfile-dashboard' ) }
                        style={ { background: 'var(--border)', border: 'none', borderRadius: 3, padding: '2px 7px', cursor: 'pointer', fontSize: '.875rem' } }
                    >
                        ✕
                    </button>
                </div>
                { saveErr && <div style={ { color: 'var(--rag-red)', fontSize: '.75rem', marginTop: 2 } }>{ saveErr }</div> }
            </td>
        );
    }

    return (
        <td>
            <button
                onClick={ startEdit }
                title={ __( 'Click to edit prior year figure', 'quickfile-dashboard' ) }
                style={ { background: 'none', border: 'none', cursor: 'pointer', textAlign: 'right', width: '100%', padding: 0, color: 'inherit', font: 'inherit' } }
            >
                { value != null
                    ? <>{ fmt( value ) }<span style={ { color: 'var(--muted)', fontSize: '.7rem', marginLeft: 5 } }>✎</span></>
                    : <span style={ { color: 'var(--muted)' } }>— ✎</span>
                }
            </button>
        </td>
    );
}

// ─── SOFA table ───────────────────────────────────────────────────────────────────────────────────

function SoFATable( { data, priorYear, priorFY, currentFYLabel, priorFYLabel, onPriorYearSaved } ) {
    // Combined figures only: the QuickFile v2 reports cannot be filtered by bank account.
    const combined  = data.combined?.NominalAccount ?? [];
    const colCount  = 4;

    const incomeRows = combined.filter( ( n ) => n.CategoryType === 'Income' );
    const expendRows = combined.filter( ( n ) => n.CategoryType === 'Expenditure' );

    const sumCombined = ( rows ) =>
        rows.reduce( ( s, n ) => s + parseFloat( n.Balance ?? 0 ), 0 );

    const sumPY = ( rows ) =>
        rows.reduce( ( s, n ) => s + ( priorYear[ n.NominalCode ] ?? 0 ), 0 );

    const renderSection = ( rows ) => rows.map( ( n ) => {
        const code  = n.NominalCode;
        const pyVal = priorYear[ code ] ?? null;

        return (
            <tr key={ code }>
                <td style={ { color: 'var(--muted)', fontSize: '.8125rem', whiteSpace: 'nowrap' } }>{ code }</td>
                <td>{ n.NominalName || code }</td>
                <td style={ { fontWeight: 600 } }>{ fmt( parseFloat( n.Balance ?? 0 ) ) }</td>
                <PriorYearCell fy={ priorFY } code={ code } value={ pyVal } onSaved={ onPriorYearSaved } />
            </tr>
        );
    } );

    const renderSubtotal = ( label, rows ) => {
        const pyTotal = sumPY( rows );
        return (
            <tr style={ { background: 'var(--bg)', borderTop: '2px solid var(--border)' } }>
                <td />
                <td style={ { fontWeight: 700 } }>{ label }</td>
                <td style={ { fontWeight: 700, color: 'var(--navy)' } }>{ fmt( sumCombined( rows ) ) }</td>
                <td style={ { fontWeight: 700 } }>{ fmt( pyTotal ) }</td>
            </tr>
        );
    };

    const totalIncome  = sumCombined( incomeRows );
    const totalExpend  = sumCombined( expendRows );
    const netThisYear  = totalIncome - totalExpend;
    const netPriorYear = sumPY( incomeRows ) - sumPY( expendRows );

    const navyRow = ( label ) => (
        <tr style={ { background: 'var(--navy)' } }>
            <td colSpan={ colCount } style={ { color: 'var(--white)', fontWeight: 700, padding: '8px 14px', fontSize: '.875rem', textTransform: 'uppercase', letterSpacing: '.06em' } }>
                { label }
            </td>
        </tr>
    );

    return (
        <div className="wb-table-wrap">
            <table className="wb-table">
                <thead>
                    <tr>
                        <th style={ { width: 68 } }>{ __( 'Code', 'quickfile-dashboard' ) }</th>
                        <th>{ __( 'Description', 'quickfile-dashboard' ) }</th>
                        <th>
                            { currentFYLabel }
                            <span style={ { display: 'block', fontWeight: 400, fontSize: '.75rem' } }>£</span>
                        </th>
                        <th>
                            { priorFYLabel }
                            <span style={ { display: 'block', fontWeight: 400, fontSize: '.75rem' } }>
                                £{ isAdmin && <em style={ { color: 'rgba(255,255,255,.65)', marginLeft: 4 } }>{ __( '(click to edit)', 'quickfile-dashboard' ) }</em> }
                            </span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    { navyRow( __( 'Income and Endowments', 'quickfile-dashboard' ) ) }
                    { renderSection( incomeRows ) }
                    { renderSubtotal( __( 'Total Income and Endowments', 'quickfile-dashboard' ), incomeRows ) }

                    { navyRow( __( 'Expenditure', 'quickfile-dashboard' ) ) }
                    { renderSection( expendRows ) }
                    { renderSubtotal( __( 'Total Expenditure', 'quickfile-dashboard' ), expendRows ) }
                </tbody>

                <tfoot>
                    <tr>
                        <td />
                        <td style={ { fontWeight: 700, color: 'var(--navy)' } }>
                            { __( 'Net Income / (Expenditure)', 'quickfile-dashboard' ) }
                        </td>
                        <td style={ { fontWeight: 700, color: netThisYear >= 0 ? 'var(--rag-green)' : 'var(--rag-red)' } }>
                            { fmt( netThisYear ) }
                        </td>
                        <td style={ { fontWeight: 700, color: netPriorYear >= 0 ? 'var(--rag-green)' : 'var(--rag-red)' } }>
                            { fmt( netPriorYear ) }
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

// ─── Bank account balances at year end ───────────────────────────────────────────────

function BalanceSheetSummary( { balanceSheet, asOf } ) {
    if ( ! balanceSheet?.accounts ) return null;
    const total = selectedAccounts.reduce( ( s, acc ) => {
        const b = balanceSheet.accounts[ String( acc.bankId ) ]?.balance;
        return s + ( b ?? 0 );
    }, 0 );
    return (
        <div className="wb-card" style={ { marginTop: 20 } }>
            <h3 className="wb-card__title">
                { __( 'Bank Account Balances at Year End', 'quickfile-dashboard' ) }
                <span style={ { fontWeight: 400, fontSize: '.875rem', color: 'var(--muted)', marginLeft: 12 } }>
                    { asOf }
                </span>
            </h3>
            <div className="wb-table-wrap">
                <table className="wb-table">
                    <thead>
                        <tr>
                            <th>{ __( 'Account', 'quickfile-dashboard' ) }</th>
                            <th style={ { textAlign: 'right' } }>{ __( 'Balance', 'quickfile-dashboard' ) }</th>
                        </tr>
                    </thead>
                    <tbody>
                        { selectedAccounts.map( ( acc ) => {
                            const row = balanceSheet.accounts[ String( acc.bankId ) ];
                            return (
                                <tr key={ acc.bankId }>
                                    <td>{ acc.name }</td>
                                    <td style={ { textAlign: 'right' } }>
                                        { row?.balance != null
                                            ? fmt( row.balance )
                                            : <span style={ { color: 'var(--muted)' } }>—</span> }
                                    </td>
                                </tr>
                            );
                        } ) }
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style={ { fontWeight: 700 } }>{ __( 'Total', 'quickfile-dashboard' ) }</td>
                            <td style={ { fontWeight: 700, textAlign: 'right' } }>{ fmt( total ) }</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────────────────────────────

export default function AnnualStatement() {
    const { globalFY } = useFY();
    const [ data,         setData         ] = useState( null );
    const [ priorYear,    setPriorYear    ] = useState( {} );
    const [ balanceSheet, setBalanceSheet ] = useState( null );
    const [ loading,      setLoading      ] = useState( false );
    const [ error,        setError        ] = useState( null );

    const currentFY = parseFYStart( globalFY?.from );
    const priorFY   = currentFY - 1;

    const fetchData = ( from, to ) => {
        setLoading( true );
        setError( null );
        Promise.all( [
            api.getAnnualStatement( from, to ),
            api.getPriorYear( parseFYStart( from ) - 1 ),
            api.getBalanceSheet( to ),
        ] )
            .then( ( [ stmt, py, bs ] ) => {
                setData( stmt );
                setPriorYear( py );
                setBalanceSheet( bs );
            } )
            .catch( ( e ) => setError( e.message ) )
            .finally( () => setLoading( false ) );
    };

    useEffect( () => {
        if ( globalFY ) fetchData( globalFY.from, globalFY.to );
    }, [ globalFY ] );

    const handlePriorYearSaved = useCallback( ( code, amount ) => {
        setPriorYear( ( prev ) => ( { ...prev, [ code ]: amount } ) );
    }, [] );

    return (
        <div>
            <p style={ { fontSize: '.8125rem', color: 'var(--muted)', marginBottom: 16 } }>
                { __( 'Statement of Financial Activities (Charity SORP).', 'quickfile-dashboard' ) }
                { ' ' }{ __( 'Period:', 'quickfile-dashboard' ) } { globalFY?.from } { __( 'to', 'quickfile-dashboard' ) } { globalFY?.to }
                { isAdmin && <em>{ ' — ' }{ __( 'Prior year figures: click any cell to edit.', 'quickfile-dashboard' ) }</em> }
            </p>

            { error && <ErrorMessage message={ error } /> }
            { loading && <LoadingSpinner /> }

            { ! loading && data && (
                <>
                    <div className="wb-card">
                        <h3 className="wb-card__title">
                            { __( 'Statement of Financial Activities', 'quickfile-dashboard' ) }
                            <span style={ { fontWeight: 400, fontSize: '.875rem', color: 'var(--muted)', marginLeft: 12 } }>
                                { fyLabel( currentFY ) }
                            </span>
                        </h3>
                        <SoFATable
                            data={ data }
                            priorYear={ priorYear }
                            priorFY={ priorFY }
                            currentFYLabel={ fyLabel( currentFY ) }
                            priorFYLabel={ fyLabel( priorFY ) }
                            onPriorYearSaved={ handlePriorYearSaved }
                        />
                    </div>
                    <BalanceSheetSummary balanceSheet={ balanceSheet } asOf={ globalFY?.to } />
                </>
            ) }

            { ! loading && ! data && ! error && (
                <p className="wb-empty">
                    { __( 'Select a date range and click Load Data.', 'quickfile-dashboard' ) }
                </p>
            ) }
        </div>
    );
}
