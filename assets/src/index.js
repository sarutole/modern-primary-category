/**
 * Internal dependencies
 */
import PrimaryTermSelector from './primary-term-selector';

/**
 * For supported taxonomies, replace the standard taxonomies metabox with a custom React component
 *
 * @param OriginalComponent
 * @uses window.stmpcParams
 *
 * @returns {Function} Custom react component
 */
function CustomizeTaxonomySelector( OriginalComponent ) {
  return function( props ) {
    if ( window.stmpcParams.supportedTaxonomies.includes( props.slug ) ) {
      props.metafieldName = window.stmpcParams.metafieldName;

      return wp.element.createElement(
        PrimaryTermSelector,
        props
      );
    } else {

      return wp.element.createElement(
        OriginalComponent,
        props
      );
    }
  }
};

wp.hooks.addFilter(
  'editor.PostTaxonomyType',
  'st-mpc',
  CustomizeTaxonomySelector
);
