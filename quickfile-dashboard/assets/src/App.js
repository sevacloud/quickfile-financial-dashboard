import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Nav from './components/Nav';
import Dashboard from './components/Dashboard';
import MonthlySummary from './components/MonthlySummary';
import Projects from './components/Projects';
import Utilities from './components/Utilities';
import AnnualStatement from './components/AnnualStatement';
import YearComparison from './components/YearComparison';
import { FYContext } from './FYContext';
import './styles/dashboard.css';

const { fyYears = [], businessName = '', basePath = '/quickfile-dashboard/' } = window.qfdData || {};

const VIEW_TITLES = {
    'dashboard':        __( 'Dashboard',           'quickfile-dashboard' ),
    'monthly-summary':  __( 'Monthly Summary',     'quickfile-dashboard' ),
    'projects':         __( 'Projects',            'quickfile-dashboard' ),
    'utilities':        __( 'Utilities',           'quickfile-dashboard' ),
    'annual-statement': __( 'Annual Statement',    'quickfile-dashboard' ),
    'year-comparison':  __( '3-Year Comparison',   'quickfile-dashboard' ),
};

function ViewContent( { view } ) {
    switch ( view ) {
        case 'dashboard':        return <Dashboard />;
        case 'monthly-summary':  return <MonthlySummary />;
        case 'projects':         return <Projects />;
        case 'utilities':        return <Utilities />;
        case 'annual-statement': return <AnnualStatement />;
        case 'year-comparison':  return <YearComparison />;
        default:                 return <Dashboard />;
    }
}

// Views live at <basePath><view>, e.g. /quickfile-dashboard/monthly-summary.
// The dashboard view is the base path itself.
function pathForView( v ) {
    return v === 'dashboard' ? basePath : basePath + v;
}

function viewFromLocation() {
    // Legacy #view links still resolve.
    const hash = window.location.hash.slice( 1 );
    if ( VIEW_TITLES[ hash ] ) {
        return hash;
    }
    const path = window.location.pathname;
    const slug = path.startsWith( basePath )
        ? path.slice( basePath.length ).replace( /\/+$/, '' )
        : '';
    return VIEW_TITLES[ slug ] ? slug : 'dashboard';
}

export default function App() {
    const [ view,     setView     ] = useState( viewFromLocation );
    const [ menuOpen, setMenuOpen ] = useState( false );
    const [ globalFY, setGlobalFY ] = useState( () => fyYears[ 0 ] ?? null );

    useEffect( () => {
        // Normalise the address bar (drops legacy hashes and unknown sub-paths).
        window.history.replaceState( null, '', pathForView( viewFromLocation() ) + window.location.search );

        const onPop = () => setView( viewFromLocation() );
        window.addEventListener( 'popstate', onPop );
        return () => window.removeEventListener( 'popstate', onPop );
    }, [] );

    const navigate = useCallback( ( v ) => {
        if ( pathForView( v ) !== window.location.pathname ) {
            window.history.pushState( null, '', pathForView( v ) + window.location.search );
        }
        setView( v );
    }, [] );

    const now = new Date().toLocaleDateString( 'en-GB', {
        day: '2-digit', month: 'long', year: 'numeric',
    } );

    const handleFYChange = ( e ) => {
        const selected = fyYears.find( ( y ) => y.label === e.target.value );
        if ( selected ) setGlobalFY( selected );
    };

    return (
        <FYContext.Provider value={ { globalFY, setGlobalFY } }>
            <div className="wb-layout">
                <Nav
                    activeView={ view }
                    onNavigate={ navigate }
                    isOpen={ menuOpen }
                    onClose={ () => setMenuOpen( false ) }
                />
                <main className="wb-main" id="main-content" tabIndex="-1">
                    <header className="wb-topbar">
                        <div className="wb-topbar__left">
                            <button
                                className="wb-burger"
                                onClick={ () => setMenuOpen( true ) }
                                aria-label={ __( 'Open menu', 'quickfile-dashboard' ) }
                                aria-expanded={ menuOpen }
                                aria-controls="wb-sidebar"
                            >
                                <span /><span /><span />
                            </button>
                            <span className="wb-topbar__title">{ VIEW_TITLES[ view ] }</span>
                        </div>

                        { globalFY && fyYears.length > 1 && (
                            <div className="wb-topbar__fy">
                                <label htmlFor="wb-global-fy" className="wb-topbar__fy-label">
                                    { __( 'Period', 'quickfile-dashboard' ) }
                                </label>
                                <select
                                    id="wb-global-fy"
                                    value={ globalFY.label }
                                    onChange={ handleFYChange }
                                    className="wb-topbar__fy-select"
                                >
                                    { fyYears.map( ( y ) => (
                                        <option key={ y.label } value={ y.label }>
                                            { y.label }{ y === fyYears[ 0 ] ? __( ' (current)', 'quickfile-dashboard' ) : '' }
                                        </option>
                                    ) ) }
                                </select>
                            </div>
                        ) }

                        <span className="wb-topbar__meta">
                            { businessName || __( 'QuickFile Dashboard', 'quickfile-dashboard' ) }
                            { ' · ' }{ now }
                        </span>
                    </header>
                    <div className="wb-content">
                        <ViewContent view={ view } />
                    </div>
                </main>
            </div>
        </FYContext.Provider>
    );
}